<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\InvoicingService;
use App\Services\SmsService;
use App\Services\TenantCredit;

/*
 * Overpaid rent carries forward: whatever a tenant pays beyond what an invoice
 * needs is applied to their other unpaid invoices, and then to the next
 * invoice raised for them.
 */

beforeEach(function () {
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    $this->sentSms = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sentSms[] = [$phone, $message];

            return true;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

/** Grace renting A4 at 500,000 a month, with one unpaid October invoice. */
function creditTenancy(): array
{
    $landlord = Landlord::factory()->create(['collect_payments' => true, 'sms_balance' => 50]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::set($landlord->id);

    $unit = Unit::create(['name' => 'A4', 'estate_id' => Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-00001', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => '2026-11-01',
    ]);
    $october = creditInvoice($lease, 'INV-OCT', '2026-10-01');
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'tenant', 'lease', 'october', 'unit');
}

function creditInvoice(Lease $lease, string $number, string $due, int $amount = 500000): Invoice
{
    return CurrentLandlord::runAs($lease->landlord_id, fn () => Invoice::create([
        'number' => $number, 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => "Rent {$number}", 'invoice_date' => $due, 'amount' => $amount, 'due_date' => $due, 'status' => 'unpaid',
    ]));
}

/** The tenant pays $amount by mobile money against $invoice (as the portal / USSD do). */
function payOnline(Landlord $landlord, Tenant $tenant, int $amount, ?Invoice $invoice = null): Payment
{
    $tx = PaymentTransaction::create([
        'reference' => 'RNT-'.fake()->unique()->bothify('######-??????'), 'purpose' => 'rent', 'landlord_id' => $landlord->id, 'gateway' => 'fake',
        'amount' => $amount, 'fee_amount' => 0, 'fee_bearer' => 'landlord', 'landlord_amount' => $amount, 'msisdn' => '256772123456', 'narrative' => 'x',
        'status' => 'pending', 'payable_type' => Tenant::class, 'payable_id' => $tenant->id,
        'context' => ['tenant_id' => $tenant->id, 'invoice_id' => $invoice?->id, 'lease_id' => $invoice?->lease_id],
    ]);
    app(\App\Payments\Purposes\RentPurpose::class)->onSucceeded($tx->fresh());

    return Payment::withoutGlobalScopes()->latest('id')->first();
}

function invoiceStatus(Invoice $invoice): string
{
    return Invoice::withoutGlobalScopes()->find($invoice->id)->status;
}

test('paying more than an invoice puts the extra on the tenant\'s other unpaid invoices', function () {
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'october' => $october] = creditTenancy();
    $november = creditInvoice($lease, 'INV-NOV', '2026-11-01');

    $payment = payOnline($landlord, $tenant, 700000, $october);
    CurrentLandlord::set($landlord->id);

    expect(invoiceStatus($october))->toBe('paid')->and(invoiceStatus($november))->toBe('partial')
        ->and($payment->status)->toBe('fully_allocated')
        ->and((float) PaymentAllocation::withoutGlobalScopes()->where('invoice_id', $november->id)->sum('amount'))->toBe(200000.0);
});

test('extra with nothing else unpaid is kept, and pays the next invoice when it is raised', function () {
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'october' => $october] = creditTenancy();

    $payment = payOnline($landlord, $tenant, 1200000, $october);
    CurrentLandlord::set($landlord->id);
    expect(invoiceStatus($october))->toBe('paid')->and($payment->status)->toBe('partially_allocated')
        ->and($payment->unallocatedAmount())->toBe(700000.0);

    // November is raised: paid in full from the 700,000; 200,000 left over.
    $this->sentSms = [];
    $november = app(InvoicingService::class)->createRecurringInvoice($lease->fresh());
    app(\App\Services\SmsNotificationService::class)->invoiceCreated($november->load('lease.tenant'));
    expect($november->status)->toBe('paid')->and($payment->fresh()->unallocatedAmount())->toBe(200000.0)
        ->and(end($this->sentSms)[1])->toContain('paid in full from your advance payment');

    // December: 200,000 from credit, 300,000 still due.
    $december = app(InvoicingService::class)->createRecurringInvoice($lease->fresh());
    app(\App\Services\SmsNotificationService::class)->invoiceCreated($december->load('lease.tenant'));
    expect($december->status)->toBe('partial')->and($payment->fresh()->status)->toBe('fully_allocated')
        ->and(end($this->sentSms)[1])->toContain('UGX 200,000 was paid from your advance payment; UGX 300,000 is due');
});

test('an invoice the landlord raises by hand also uses the tenant\'s credit', function () {
    ['landlord' => $landlord, 'admin' => $admin, 'tenant' => $tenant, 'lease' => $lease, 'october' => $october] = creditTenancy();
    payOnline($landlord, $tenant, 600000, $october);

    $this->actingAs($admin)->post(route('invoices.store'), [
        'lease_id' => $lease->id, 'type' => 'utility', 'title' => 'Water, October', 'invoice_date' => today()->toDateString(),
        'due_date' => today()->addWeek()->toDateString(), 'amount' => 80000,
    ])->assertSessionHas('success');

    $water = Invoice::withoutGlobalScopes()->where('title', 'Water, October')->sole();
    expect($water->status)->toBe('paid');
});

test('older payments are used first, oldest invoices are paid first, and nobody else\'s money is touched', function () {
    ['landlord' => $landlord, 'tenant' => $tenant, 'lease' => $lease, 'october' => $october] = creditTenancy();
    ['tenant' => $other, 'lease' => $otherLease] = creditTenancy();
    CurrentLandlord::set($landlord->id);

    $pay = fn (Tenant $t, int $amount, string $date, string $status = 'unallocated') => Payment::withoutGlobalScopes()->create([
        'landlord_id' => $t->landlord_id, 'number' => 'PAY-'.fake()->unique()->randomNumber(5), 'payable_type' => Tenant::class, 'payable_id' => $t->id,
        'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash', 'status' => $status,
    ]);
    $newer = $pay($tenant, 300000, '2026-10-20');
    $older = $pay($tenant, 300000, '2026-10-05');
    $cancelled = $pay($tenant, 900000, '2026-10-01', 'cancelled');
    $othersMoney = $pay($other, 900000, '2026-10-01');
    $november = creditInvoice($lease, 'INV-NOV', '2026-11-01');

    expect(app(TenantCredit::class)->apply($tenant->id))->toBe(600000.0);

    expect(invoiceStatus($october))->toBe('paid')->and(invoiceStatus($november))->toBe('partial')
        ->and($older->fresh()->status)->toBe('fully_allocated')->and($newer->fresh()->unallocatedAmount())->toBe(0.0)
        ->and(PaymentAllocation::withoutGlobalScopes()->where('payment_id', $older->id)->value('invoice_id'))->toBe($october->id)
        ->and($cancelled->fresh()->allocations()->count())->toBe(0)
        ->and($othersMoney->fresh()->status)->toBe('unallocated')
        ->and(invoiceStatus(Invoice::withoutGlobalScopes()->where('lease_id', $otherLease->id)->first()))->toBe('unpaid');

    // Nothing left to apply: a second run changes nothing.
    expect(app(TenantCredit::class)->apply($tenant->id))->toBe(0.0);
});
