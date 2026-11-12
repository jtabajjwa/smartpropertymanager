<?php

use App\Http\Middleware\AuthenticateTenant;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\PaymentReceipt;
use App\Support\AmountInWords;

/*
 * Payment receipts (PDF) for landlords (e.g. for cash) and tenants: what was
 * paid for, what is still owed on each invoice, advance payments, and the
 * tenant's balance now.
 */

afterEach(fn () => CurrentLandlord::clear());

/** Grace in Acacia Court A4 with October (500,000, due) and November (500,000) unpaid. */
function receiptSetup(): array
{
    $landlord = Landlord::factory()->create(['name' => 'Kato Properties', 'contact_phone' => '+2567001'.fake()->unique()->numerify('#####')]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id, 'name' => 'Sarah Admin']);
    CurrentLandlord::set($landlord->id);

    $unit = Unit::create(['name' => 'A4', 'estate_id' => Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-00001', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => '2026-12-01',
    ]);
    $inv = fn (string $no, string $title, string $due) => Invoice::create([
        'number' => $no, 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => $title, 'invoice_date' => $due, 'amount' => 500000, 'due_date' => $due, 'status' => 'unpaid',
    ]);
    $october = $inv('INV-OCT', 'Rent October 2026', '2026-10-01');
    $november = $inv('INV-NOV', 'Rent November 2026', '2026-11-01');
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'tenant', 'lease', 'october', 'november');
}

test('amounts are written in words the way receipts are', function () {
    expect(AmountInWords::shillings(750000))->toBe('Seven hundred fifty thousand shillings only')
        ->and(AmountInWords::shillings(1250000))->toBe('One million two hundred fifty thousand shillings only')
        ->and(AmountInWords::shillings(21))->toBe('Twenty-one shillings only')
        ->and(AmountInWords::shillings(3000105))->toBe('Three million one hundred five shillings only')
        ->and(AmountInWords::shillings(0))->toBe('Zero shillings only');
});

test('a cash payment is applied to unpaid invoices and its receipt shows what it paid and what is owed', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'october' => $october, 'november' => $november, 'landlord' => $landlord] = receiptSetup();

    $this->actingAs($admin)->post(route('payments.store'), [
        'tenant_id' => $tenant->id, 'amount' => 750000, 'payment_date' => '2026-11-12', 'payment_method' => 'cash', 'apply_to_invoices' => 1,
    ])->assertSessionHas('receipt_payment_id');

    $payment = Payment::withoutGlobalScopes()->sole();
    expect($payment->status)->toBe('fully_allocated')
        ->and(Invoice::withoutGlobalScopes()->find($october->id)->status)->toBe('paid')
        ->and(Invoice::withoutGlobalScopes()->find($november->id)->status)->toBe('partial');

    // The banner offers the receipt.
    $this->actingAs($admin)->get(route('payments.index'))->assertOk()->assertSee('Print receipt')->assertSee(route('payments.receipt', $payment));

    CurrentLandlord::set($landlord->id);
    $data = app(PaymentReceipt::class)->data($payment);
    expect($data['lines']->pluck('paid_now')->all())->toBe([500000.0, 250000.0])
        ->and($data['lines']->pluck('balance')->all())->toBe([0.0, 250000.0])
        ->and($data['advance'])->toBe(0.0)->and($data['owed'])->toBe(250000.0)
        ->and($data['receivedBy'])->toBe('Sarah Admin')->and($data['method'])->toBe('Cash');

    $html = view('pdfs.receipt', $data)->render();
    expect($html)->toContain('RECEIPT')->toContain('Seven hundred fifty thousand shillings only')->toContain('Grace Namata')
        ->toContain('Acacia Court · A4')->toContain('Rent October 2026')->toContain('Balance outstanding')->toContain('UGX 250,000');

    $this->actingAs($admin)->get(route('payments.receipt', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('without applying, a payment is unallocated; extra beyond all invoices shows as paid in advance', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'landlord' => $landlord] = receiptSetup();

    $this->actingAs($admin)->post(route('payments.store'), ['tenant_id' => $tenant->id, 'amount' => 100000, 'payment_date' => '2026-11-12', 'payment_method' => 'cash']);
    expect(Payment::withoutGlobalScopes()->sole()->status)->toBe('unallocated');

    $this->actingAs($admin)->post(route('payments.store'), ['tenant_id' => $tenant->id, 'amount' => 1100000, 'payment_date' => '2026-11-12', 'payment_method' => 'bank_transfer', 'reference' => 'TT-9', 'apply_to_invoices' => 1]);

    CurrentLandlord::set($landlord->id);
    $data = app(PaymentReceipt::class)->data(Payment::withoutGlobalScopes()->latest('id')->first());
    // The earlier 100,000 went on October first (oldest payment first), so this one paid 400,000 + 500,000.
    expect($data['lines']->sum('paid_now'))->toBe(900000.0)->and($data['advance'])->toBe(200000.0)
        ->and($data['owed'])->toBe(0.0)->and($data['credit'])->toBe(200000.0);
    expect(view('pdfs.receipt', $data)->render())->toContain('Paid in advance')->toContain('Fully paid up')->toContain('TT-9');
});

test('tenants download receipts for their own payments only', function () {
    ['tenant' => $tenant, 'landlord' => $landlord] = receiptSetup();
    ['tenant' => $other] = receiptSetup();
    $pay = fn (Tenant $t) => CurrentLandlord::runAs($t->landlord_id, fn () => Payment::create([
        'number' => 'PAY-'.fake()->unique()->randomNumber(5), 'payable_type' => Tenant::class, 'payable_id' => $t->id, 'amount' => 50000,
        'payment_date' => '2026-11-12', 'payment_method' => 'mtn_mobile_money', 'status' => 'unallocated',
    ]));
    $mine = $pay($tenant);
    $theirs = $pay($other);

    $as = fn () => $this->withSession([AuthenticateTenant::SESSION => ['msisdn' => '256772123456', 'tenant_ids' => [$tenant->id], 'tenant_id' => $tenant->id]]);
    $as()->get(route('tenant.payments'))->assertOk()->assertSee(route('tenant.payments.receipt', $mine->id));
    $as()->get(route('tenant.payments.receipt', $mine->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    $as()->get(route('tenant.payments.receipt', $theirs->id))->assertNotFound();
});

test('a cancelled payment\'s receipt is stamped cancelled', function () {
    ['tenant' => $tenant, 'landlord' => $landlord] = receiptSetup();
    CurrentLandlord::set($landlord->id);
    $payment = Payment::create([
        'number' => 'PAY-00009', 'payable_type' => Tenant::class, 'payable_id' => $tenant->id, 'amount' => 50000,
        'payment_date' => '2026-11-12', 'payment_method' => 'cash', 'status' => 'cancelled',
    ]);

    $html = view('pdfs.receipt', app(PaymentReceipt::class)->data($payment))->render();
    expect($html)->toContain('CANCELLED')->not->toContain('Balance outstanding');
});
