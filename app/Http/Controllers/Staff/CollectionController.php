<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CollectionFeeTier;
use App\Models\Landlord;
use App\Models\LandlordSettlement;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rent collected from tenants by mobile money into the Primora pool, on
 * behalf of landlords: which landlords it's switched on for, the collection
 * fee bands, what each landlord is owed, and settlements paid to them.
 *
 * Owed to a landlord = Σ landlord_amount of their succeeded rent payments
 * (amount less the fee when the landlord bears it) − Σ settlements.
 */
class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->withTotals(Landlord::query());

        if ($request->input('show') !== 'all') {
            $query->where(fn ($q) => $q->where('collect_payments', true)->orWhereHas('paymentTransactions', fn ($p) => $p->where('purpose', 'rent')));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('contact_email', 'like', "%{$search}%"));
        }

        $landlords = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('staff.collections.index', [
            'landlords' => $landlords,
            'tiers' => CollectionFeeTier::orderBy('min_amount')->get(),
            'totals' => [
                'collected' => (float) PaymentTransaction::where('purpose', 'rent')->where('status', PaymentTransaction::SUCCEEDED)->sum('amount'),
                'fees' => (float) PaymentTransaction::where('purpose', 'rent')->where('status', PaymentTransaction::SUCCEEDED)->sum('fee_amount'),
                'owed' => (float) PaymentTransaction::where('purpose', 'rent')->where('status', PaymentTransaction::SUCCEEDED)->sum('landlord_amount'),
                'settled' => (float) LandlordSettlement::sum('amount'),
                'month' => (float) PaymentTransaction::where('purpose', 'rent')->where('status', PaymentTransaction::SUCCEEDED)->where('completed_at', '>=', now()->startOfMonth())->sum('amount'),
            ],
            'enabledCount' => Landlord::where('collect_payments', true)->count(),
        ]);
    }

    public function show(Landlord $landlord)
    {
        $landlord = $this->withTotals(Landlord::query())->findOrFail($landlord->id);

        return view('staff.collections.show', [
            'landlord' => $landlord,
            'collections' => PaymentTransaction::where('landlord_id', $landlord->id)->where('purpose', 'rent')->latest('id')->paginate(20, ['*'], 'collections'),
            'settlements' => LandlordSettlement::with('recordedBy')->where('landlord_id', $landlord->id)->latest('settled_on')->latest('id')->get(),
        ]);
    }

    /** Switch tenant mobile money payments on or off for a landlord. */
    public function toggle(Request $request, Landlord $landlord)
    {
        $landlord->withAuditNote(($landlord->collect_payments ? 'Tenant payments switched off' : 'Tenant payments switched on').' by staff')
            ->update(['collect_payments' => !$landlord->collect_payments]);

        return back()->with('success', $landlord->collect_payments
            ? "{$landlord->name}'s tenants can now pay by mobile money."
            : "Mobile money payments switched off for {$landlord->name}'s tenants.");
    }

    public function storeSettlement(Request $request, Landlord $landlord)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'method' => ['required', Rule::in(array_keys(LandlordSettlement::METHODS))],
            'settled_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        LandlordSettlement::create($data + ['landlord_id' => $landlord->id, 'recorded_by_staff_user_id' => $request->user('staff')->id]);

        return back()->with('success', 'Settlement of UGX '.number_format((float) $data['amount']).' recorded.');
    }

    public function destroySettlement(Landlord $landlord, LandlordSettlement $settlement)
    {
        abort_unless($settlement->landlord_id === $landlord->id, 404);
        $settlement->delete();

        return back()->with('success', 'Settlement deleted.');
    }

    // --- Fee bands --------------------------------------------------------

    public function storeTier(Request $request)
    {
        CollectionFeeTier::create($this->tierData($request));

        return back()->with('success', 'Fee band added.');
    }

    public function updateTier(Request $request, CollectionFeeTier $tier)
    {
        $tier->update($this->tierData($request, $tier));

        return back()->with('success', 'Fee band updated.');
    }

    public function destroyTier(CollectionFeeTier $tier)
    {
        $tier->delete();

        return back()->with('success', 'Fee band deleted.');
    }

    protected function tierData(Request $request, ?CollectionFeeTier $ignore = null): array
    {
        $data = $request->validate([
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'fixed_fee' => ['required', 'numeric', 'min:0'],
            'percent_fee' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ]);
        $data['percent_fee'] = $data['percent_fee'] ?? 0;

        // Bands must not overlap, or an amount would have two fees.
        $min = (float) $data['min_amount'];
        $max = isset($data['max_amount']) ? (float) $data['max_amount'] : PHP_FLOAT_MAX;
        $overlap = CollectionFeeTier::when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))->get()
            ->first(fn (CollectionFeeTier $t) => $min <= ($t->max_amount === null ? PHP_FLOAT_MAX : (float) $t->max_amount) && (float) $t->min_amount <= $max);

        if ($overlap) {
            throw \Illuminate\Validation\ValidationException::withMessages(['min_amount' => "This band overlaps the {$overlap->label()} band."]);
        }

        return $data;
    }

    protected function withTotals(Builder $query): Builder
    {
        $rent = fn ($q) => $q->where('purpose', 'rent')->where('status', PaymentTransaction::SUCCEEDED);

        return $query
            ->withSum(['paymentTransactions as rent_collected' => $rent], 'amount')
            ->withSum(['paymentTransactions as rent_fees' => $rent], 'fee_amount')
            ->withSum(['paymentTransactions as rent_owed' => $rent], 'landlord_amount')
            ->withCount(['paymentTransactions as rent_payments' => $rent])
            ->withSum('settlements as settled', 'amount')
            ->withMax(['paymentTransactions as last_collected_at' => $rent], 'completed_at');
    }
}
