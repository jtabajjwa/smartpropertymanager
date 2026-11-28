<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\InvoiceEmailService;
use App\Services\SmsNotificationService;
use App\Services\TenantCredit;
use Illuminate\Support\Facades\DB;

/**
 * An invoice raised by hand (rent, deposit, utility; or utility for a casual
 * customer). Shared by the web portal and the mobile app's API.
 *
 * $data is StoreInvoiceRequest's validated data.
 */
class CreateInvoice
{
    public function __invoke(array $data): Invoice
    {
        $sendSms = (bool) ($data['send_sms'] ?? false);
        $sendEmail = (bool) ($data['send_email'] ?? false);
        unset($data['send_sms'], $data['send_email']);

        $invoice = DB::transaction(function () use ($data) {
            if (isset($data['customer_id'])) {
                $data['invoiceable_type'] = Customer::class;
                $data['invoiceable_id'] = $data['customer_id'];
                unset($data['customer_id']);
            } elseif (isset($data['lease_id'])) {
                $data['invoiceable_type'] = Lease::class;
                $data['invoiceable_id'] = $data['lease_id'];
            }

            $invoice = Invoice::create($data);
            $invoice->update(['number' => app(\App\Services\NumberGenerator::class)->next($invoice->landlord_id, 'invoice', 'INV', table: 'invoices')]);

            // A tenant's money paid in advance pays the new invoice.
            if ($invoice->invoiceable_type === Lease::class && ($tenantId = Lease::whereKey($invoice->lease_id)->value('tenant_id'))) {
                app(TenantCredit::class)->apply($tenantId);
                $invoice->refresh();
            }

            return $invoice;
        });

        app(\App\Services\Push\PushNotifications::class)->invoiceIssued($invoice);
        if ($sendSms) {
            app(SmsNotificationService::class)->invoiceCreated($invoice->load('lease.tenant'));
        }
        if ($sendEmail) {
            app(InvoiceEmailService::class)->invoiceCreated($invoice->load('lease.tenant'));
        }

        return $invoice;
    }
}
