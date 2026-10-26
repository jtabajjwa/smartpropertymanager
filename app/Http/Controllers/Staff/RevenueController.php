<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsCreditTransaction;
use App\Models\SmsPurchase;
use App\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Money collected from every revenue stream (subscriptions, SMS sales),
 * by period, channel and landlord, with SMS cost and gross margin.
 * Revenue = succeeded payments, dated by when the money was received.
 */
class RevenueController extends Controller
{
    /** Payment purpose => label, in display order. */
    public const STREAMS = ['subscription' => 'Subscriptions', 'sms' => 'SMS sales'];

    public const PERIODS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'last_12' => 'Last 12 months',
        'custom' => 'Custom',
    ];

    public function index(Request $request)
    {
        $request->validate([
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        [$period, $from, $to] = $this->range($request);
        $days = (int) $from->diffInDays($to) + 1;
        [$prevFrom, $prevTo] = [$from->subDays($days), $from->subSecond()];

        $current = $this->totals($from, $to);
        $previous = $this->totals($prevFrom, $prevTo);

        // SMS cost: SMS actually sent in the period × average provider cost per SMS.
        $purchased = (int) SmsPurchase::sum('quantity');
        $avgCost = $purchased > 0 ? (float) SmsPurchase::sum('total_cost') / $purchased : null;
        $smsSent = (int) -SmsCreditTransaction::whereIn('type', ['usage', 'refund'])->whereBetween('created_at', [$from, $to])->sum('quantity');
        $smsCost = $avgCost !== null ? $smsSent * $avgCost : null;
        $smsSold = (int) SmsCreditTransaction::where('type', 'purchase')->whereBetween('created_at', [$from, $to])->sum('quantity');

        $succeeded = fn () => PaymentTransaction::where('status', PaymentTransaction::SUCCEEDED)->whereBetween('completed_at', [$from, $to]);

        // By channel: Yo! (and future gateways) vs each offline method.
        $channels = $succeeded()->get(['gateway', 'context', 'amount'])
            ->groupBy(fn (PaymentTransaction $p) => $p->gateway === 'manual'
                ? (PaymentService::OFFLINE_METHODS[$p->context['method'] ?? ''] ?? 'Offline')
                : ($p->gateway === 'yo' ? 'Yo! Payments (mobile money)' : ucfirst($p->gateway)))
            ->map(fn ($group) => ['amount' => (float) $group->sum('amount'), 'count' => $group->count()])
            ->sortByDesc('amount');

        $topLandlords = $succeeded()->selectRaw('landlord_id, purpose, SUM(amount) as total, COUNT(*) as payments')
            ->groupBy('landlord_id', 'purpose')->get()
            ->groupBy('landlord_id')
            ->map(fn ($rows, $id) => [
                'landlord_id' => $id,
                'by' => $rows->mapWithKeys(fn ($r) => [$r->purpose => (float) $r->total])->all(),
                'total' => (float) $rows->sum('total'),
                'payments' => (int) $rows->sum('payments'),
            ])
            ->sortByDesc('total')->take(10)->values();
        $names = Landlord::whereIn('id', $topLandlords->pluck('landlord_id'))->pluck('name', 'id');

        return view('staff.revenue.index', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'current' => $current,
            'previous' => $previous,
            'prevLabel' => $prevFrom->format('d M Y').' – '.$prevTo->format('d M Y'),
            'avgCost' => $avgCost,
            'smsSent' => $smsSent,
            'smsSold' => $smsSold,
            'smsCost' => $smsCost,
            'providerSpend' => (float) SmsPurchase::whereBetween('purchased_on', [$from->toDateString(), $to->toDateString()])->sum('total_cost'),
            'channels' => $channels,
            'topLandlords' => $topLandlords,
            'names' => $names,
            'months' => $this->monthly($to),
            'recent' => $succeeded()->with('landlord')->latest('completed_at')->limit(15)->get(),
        ]);
    }

    /** @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable} */
    protected function range(Request $request): array
    {
        $now = CarbonImmutable::now();
        $period = $request->input('period') ?: ($request->filled('from') ? 'custom' : 'this_month');

        [$from, $to] = match ($period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfDay()],
            'this_year' => [$now->startOfYear(), $now->endOfDay()],
            'last_12' => [$now->subMonthsNoOverflow(11)->startOfMonth(), $now->endOfDay()],
            'custom' => [
                CarbonImmutable::parse($request->input('from', $now->startOfMonth()->toDateString()))->startOfDay(),
                CarbonImmutable::parse($request->input('to', $now->toDateString()))->endOfDay(),
            ],
            default => [$now->startOfMonth(), $now->endOfDay()],
        };

        return [$period, $from, $to];
    }

    /** Collected per stream (and in total) between two moments. */
    protected function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = PaymentTransaction::where('status', PaymentTransaction::SUCCEEDED)->whereBetween('completed_at', [$from, $to])
            ->selectRaw('purpose, SUM(amount) as total, COUNT(*) as payments')->groupBy('purpose')->get()->keyBy('purpose');

        $streams = [];
        foreach (array_keys(self::STREAMS) as $purpose) {
            $streams[$purpose] = ['amount' => (float) ($rows[$purpose]->total ?? 0), 'count' => (int) ($rows[$purpose]->payments ?? 0)];
        }

        return ['streams' => $streams, 'total' => (float) $rows->sum('total'), 'count' => (int) $rows->sum('payments')];
    }

    /** The 12 months ending with the one containing $end, per stream. */
    protected function monthly(CarbonImmutable $end): array
    {
        $start = $end->startOfMonth()->subMonthsNoOverflow(11);

        $payments = PaymentTransaction::where('status', PaymentTransaction::SUCCEEDED)
            ->whereBetween('completed_at', [$start, $end->endOfMonth()])
            ->get(['purpose', 'amount', 'completed_at']);

        $months = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonthNoOverflow()) {
            $key = $m->format('Y-m');
            $months[$key] = ['label' => $m->format('M Y'), 'short' => $m->format('M'), 'subscription' => 0.0, 'sms' => 0.0];
        }

        foreach ($payments as $p) {
            $key = $p->completed_at->format('Y-m');
            if (isset($months[$key], $months[$key][$p->purpose])) {
                $months[$key][$p->purpose] += (float) $p->amount;
            }
        }

        return array_values($months);
    }
}
