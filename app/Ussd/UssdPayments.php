<?php

namespace App\Ussd;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UssdPaymentIntent;
use App\Payments\PaymentGatewayManager;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\TenantPortal\TenantLogin;
use App\Support\UnitCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rent paid through Yo!'s Custom USSD app. Yo! runs the menu and sends the
 * mobile money prompt itself; Primora answers its call-outs (see
 * YoUssdCallout) and hands out a payment reference for the chosen tenancy.
 *
 * A reference is only a UssdPaymentIntent until money moves: when Yo!'s
 * payment notification arrives with it (or a status check finds it), it
 * becomes a normal rent PaymentTransaction and is recorded like any other
 * tenant payment. References nobody pays against expire quietly.
 */
class UssdPayments
{
    public function __construct(protected PaymentService $payments, protected TenantLogin $login)
    {
    }

    /** The active tenancy on a unit, by its unit ID; null if there's none (or the ID is wrong). */
    public function tenancyForUnitCode(string $input): ?Tenancy
    {
        $code = UnitCode::normalize($input);
        $unit = $code ? Unit::withoutGlobalScopes()->where('code', $code)->first() : null;

        if (!$unit) {
            return null;
        }

        return CurrentLandlord::runAs($unit->landlord_id, function () use ($unit) {
            $lease = Lease::where('unit_id', $unit->id)->where('status', 'active')->with(['tenant', 'unit.estate'])->latest('start_date')->first();

            return $lease?->tenant ? $this->tenancy($lease) : null;
        });
    }

    /**
     * Active tenancies of the tenant(s) registered with this phone number,
     * across landlords, in a fixed order (the USSD list is numbered from it).
     *
     * @return Collection<int, Tenancy>
     */
    public function tenanciesForPhone(string $msisdn): Collection
    {
        return $this->login->tenantsWithPhone($msisdn)
            ->sortBy('id')
            ->flatMap(fn (Tenant $tenant) => CurrentLandlord::runAs($tenant->landlord_id, fn () => Lease::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->with(['tenant', 'unit.estate'])
                ->orderBy('id')
                ->get()
                ->map(fn (Lease $lease) => $this->tenancy($lease))))
            ->values();
    }

    /** A reference Yo! sends as the payment's external reference, tied to this tenancy. */
    public function reserve(Tenancy $tenancy, string $payerMsisdn, ?float $amount = null): UssdPaymentIntent
    {
        return UssdPaymentIntent::create([
            'reference' => $this->payments->newReference('rent'),
            'landlord_id' => $tenancy->lease->landlord_id,
            'tenant_id' => $tenancy->tenant->id,
            'lease_id' => $tenancy->lease->id,
            'msisdn' => $payerMsisdn,
            'amount' => $amount,
        ]);
    }

    /**
     * Turn an open reference into a rent payment transaction, once Yo! says
     * money moved against it. Null if the reference isn't one of ours.
     */
    public function claim(?string $reference, ?float $reportedAmount = null): ?PaymentTransaction
    {
        if (!$reference) {
            return null;
        }

        return DB::transaction(function () use ($reference, $reportedAmount) {
            $intent = UssdPaymentIntent::where('reference', $reference)->lockForUpdate()->first();

            if (!$intent) {
                return null;
            }
            if ($intent->payment_transaction_id) {
                return $intent->transaction;
            }

            $tenant = Tenant::withoutGlobalScopes()->find($intent->tenant_id);
            $lease = $intent->lease;
            $unit = $lease ? Unit::withoutGlobalScopes()->with(['estate' => fn ($q) => $q->withoutGlobalScopes()])->find($lease->unit_id) : null;

            $transaction = $this->payments->expected(
                purpose: 'rent',
                landlord: $intent->landlord,
                // Paying by unit ID, the amount is only typed after the call-out;
                // the status check fills in what was actually paid.
                amount: (float) ($intent->amount ?? $reportedAmount ?? 0),
                msisdn: $intent->msisdn,
                reference: $intent->reference,
                gateway: 'yo',
                context: [
                    'channel' => 'ussd',
                    'tenant_id' => $intent->tenant_id,
                    'tenant_name' => $tenant?->name,
                    'lease_id' => $intent->lease_id,
                    'unit' => $unit ? trim(($unit->estate?->name ?? '').' '.$unit->name) : null,
                    'invoice_id' => null,
                    'invoice_number' => null,
                ],
                payable: $tenant,
            );

            $intent->update(['status' => UssdPaymentIntent::CLAIMED, 'payment_transaction_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Backup for a missed notification: ask Yo! about open references now
     * and then. Any that were paid become payments; ones never used expire.
     */
    public function reconcile(): int
    {
        $validHours = (int) config('services.yo_ussd.reference_valid_hours', 3);
        $gateway = app(PaymentGatewayManager::class)->gateway('yo');
        $found = 0;

        UssdPaymentIntent::where('status', UssdPaymentIntent::OPEN)
            ->where('created_at', '<=', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(20)))
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->each(function (UssdPaymentIntent $intent) use ($gateway, $validHours, &$found) {
                $result = $gateway->checkStatus(new PaymentTransaction(['reference' => $intent->reference, 'gateway' => 'yo']));
                $intent->update(['last_checked_at' => now()]);

                if ($result->notFound || $result->status === PaymentTransaction::FAILED) {
                    if ($intent->created_at->lt(now()->subHours($validHours))) {
                        $intent->update(['status' => UssdPaymentIntent::EXPIRED]);
                    }

                    return;
                }

                // Yo! knows it: from here it's an ordinary payment.
                if ($transaction = $this->claim($intent->reference, $result->amount)) {
                    $this->payments->refresh($transaction);
                    $found++;
                }
            });

        return $found;
    }

    protected function tenancy(Lease $lease): Tenancy
    {
        $balance = (float) Invoice::where('lease_id', $lease->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->withSum('allocations as paid', 'amount')
            ->get()
            ->sum(fn (Invoice $i) => max(0, (float) $i->amount - (float) $i->paid));

        return new Tenancy($lease, $lease->tenant, $lease->unit, $lease->unit?->estate, $balance, (bool) $lease->tenant?->landlord?->collect_payments);
    }
}
