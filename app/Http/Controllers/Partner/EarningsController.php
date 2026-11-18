<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerCommission;
use App\Services\PartnerCommissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The partner's revenue share: balances, every line, and monthly statements. */
class EarningsController extends Controller
{
    public function index(Request $request, PartnerCommissions $commissions)
    {
        $partner = Auth::guard('partner')->user()->partner;

        $lines = PartnerCommission::with('landlord')->where('partner_id', $partner->id)
            ->latest('earned_at')->latest('id')->paginate(30)->withQueryString();

        $months = PartnerCommission::where('partner_id', $partner->id)->orderBy('earned_at')->pluck('earned_at')
            ->map(fn ($d) => $d->format('Y-m'))->unique()->reverse()->values();

        return view('partner.earnings', [
            'partner' => $partner,
            'balances' => $commissions->balances($partner),
            'plan' => $commissions->planFor($partner),
            'lines' => $lines,
            'months' => $months,
            'holdingDays' => PartnerCommissions::holdingDays(),
            'minPayout' => PartnerCommissions::minPayout(),
            'thisMonth' => (float) PartnerCommission::where('partner_id', $partner->id)->where('earned_at', '>=', now()->startOfMonth())->sum('net'),
        ]);
    }

    /** A month's statement as a PDF: every line, with totals and the balance. */
    public function statement(Request $request, PartnerCommissions $commissions)
    {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $partner = Auth::guard('partner')->user()->partner;
        $from = CarbonImmutable::createFromFormat('Y-m-d', $request->month.'-01')->startOfMonth();
        $to = $from->endOfMonth();

        $lines = PartnerCommission::with('landlord')->where('partner_id', $partner->id)
            ->whereBetween('earned_at', [$from, $to])->orderBy('earned_at')->orderBy('id')->get();
        $before = (float) PartnerCommission::where('partner_id', $partner->id)->where('earned_at', '<', $from)->sum('net');

        $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])
            ->loadView('pdfs.partner-statement', [
                'partner' => $partner, 'from' => $from, 'to' => $to, 'lines' => $lines,
                'opening' => $before, 'closing' => $before + (float) $lines->sum('net'),
                'balances' => $commissions->balances($partner),
            ])->setPaper('a4', 'portrait');

        return $pdf->download('primora-partner-statement-'.$partner->number.'-'.$request->month.'.pdf');
    }
}
