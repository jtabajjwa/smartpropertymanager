<?php

namespace App\Services\TenantPortal;

use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CollectionFees;

/**
 * A tenant paying an invoice, or paying in advance, by mobile money (Yo!
 * Payments, into the Primora pool), for landlords Primora collects for.
 * Shared by the tenant portal and the WhatsApp bot, so both apply the same rules.
 *
 * Runs in the tenant's landlord scope.
 */
class RentPayments
{
    public const MIN_AMOUNT = 500;
    public const MAX_AMOUNT = 5_000_000;

    public function __construct(protected CollectionFees $fees, protected PaymentService $payments)
    {
    }

    /** Whether this tenant can pay through Primora at all. */
    public function available(Tenant $tenant): bool
    {
        return (bool) $tenant->landlord->collect_payments && $this->payments->gatewayAvailable();
    }

    /** What the tenant would be charged for paying $principal (fee included when passed on). */
    public function quote(Tenant $tenant, float $principal): array
    {
        return $this->fees->quote($tenant->landlord, round($principal));
    }

    /**
     * Checks the request and sends the mobile money prompt.
     *
     * @param  array  $extraContext  saved on the transaction, e.g. where to confirm the result
     *
     * @throws RentPaymentException  with a message for the tenant (and the form field it's about)
     */
    public function start(Tenant $tenant, ?int $invoiceId, float $amount, string $phone, array $extraContext = []): PaymentTransaction
    {
        $landlord = $tenant->landlord;

        if (!$landlord->collect_payments) {
            throw new RentPaymentException("Your landlord doesn't accept mobile money payments through Primora yet.");
        }
        if ($amount < self::MIN_AMOUNT) {
            throw new RentPaymentException('The smallest amount you can pay by mobile money is UGX '.number_format(self::MIN_AMOUNT).'.', 'amount');
        }
        if ($amount > self::MAX_AMOUNT) {
            throw new RentPaymentException('The most you can pay at once is UGX '.number_format(self::MAX_AMOUNT).'.', 'amount');
        }

        $msisdn = Msisdn::normalize($phone);
        if (!$msisdn) {
            throw new RentPaymentException('Enter a Ugandan mobile money number, e.g. 0772 123 456.', 'phone');
        }

        $principal = round($amount);
        $invoice = null;

        if ($invoiceId) {
            $invoice = (new TenantAccount($tenant))->findOpenInvoice($invoiceId);

            if (!$invoice) {
                throw new RentPaymentException('That invoice is already paid or no longer available.');
            }
            if ($principal > $invoice->balance) {
                throw new RentPaymentException('That is more than the UGX '.number_format($invoice->balance).' still due on this invoice. To pay extra, make an advance payment.', 'amount');
            }
        }

        $waiting = PaymentTransaction::where('purpose', 'rent')->where('payable_type', Tenant::class)->where('payable_id', $tenant->id)
            ->open()->where('created_at', '>', now()->subMinutes(3))->exists();
        if ($waiting) {
            throw new RentPaymentException('A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        $quote = $this->fees->quote($landlord, $principal);

        if ($quote['charge'] > self::MAX_AMOUNT) {
            throw new RentPaymentException('With the collection fee that is more than UGX '.number_format(self::MAX_AMOUNT).'. Please pay a smaller amount.', 'amount');
        }

        try {
            $transaction = $this->payments->initiate(
                purpose: 'rent',
                landlord: $landlord,
                amount: $quote['charge'],
                msisdn: $msisdn,
                context: [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'invoice_id' => $invoice?->id,
                    'invoice_number' => $invoice?->number,
                    'principal' => $quote['principal'],
                    'fee' => $quote['fee'],
                    'fee_bearer' => $quote['bearer'],
                ] + $extraContext,
                payable: $tenant,
            );
        } catch (PaymentException $e) {
            throw new RentPaymentException($e->getMessage());
        }

        $transaction->update(['fee_amount' => $quote['fee'], 'fee_bearer' => $quote['bearer'], 'landlord_amount' => $quote['landlord_amount']]);

        return $transaction;
    }
}
