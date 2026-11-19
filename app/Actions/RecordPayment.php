<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use App\Services\SmsNotificationService;
use App\Services\TenantCredit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A payment the landlord received (cash, bank, mobile money). Shared by the
 * web portal and the mobile app's API, so both record payments the same way.
 *
 * $data is StorePaymentRequest's validated data.
 */
class RecordPayment
{
    public function __invoke(array $data, ?UploadedFile $receiptPhoto = null): Payment
    {
        $sendSms = (bool) ($data['send_sms'] ?? false);
        unset($data['send_sms']);

        if ($receiptPhoto) {
            $data['receipt_photo'] = $receiptPhoto->store('payments/receipts', 'public');
        }

        $payment = DB::transaction(function () use ($data) {
            if (isset($data['customer_id'])) {
                $data['payable_type'] = Customer::class;
                $data['payable_id'] = $data['customer_id'];
                unset($data['customer_id']);
            } elseif (isset($data['tenant_id'])) {
                $data['payable_type'] = Tenant::class;
                $data['payable_id'] = $data['tenant_id'];
                unset($data['tenant_id']);
            }

            $applyToInvoices = (bool) ($data['apply_to_invoices'] ?? false);
            unset($data['apply_to_invoices']);

            $payment = Payment::create($data);
            $payment->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'payment', 'PAY')]);

            // Put it on the tenant's unpaid invoices, oldest first (the rest is kept as advance payment).
            if ($applyToInvoices && $payment->payable_type === Tenant::class) {
                app(TenantCredit::class)->apply($payment->payable_id);
            }

            return $payment;
        });

        app(\App\Services\Push\PushNotifications::class)->paymentReceived($payment);
        if ($sendSms) {
            app(SmsNotificationService::class)->paymentReceived($payment->load('payable'));
        }

        return $payment;
    }
}
