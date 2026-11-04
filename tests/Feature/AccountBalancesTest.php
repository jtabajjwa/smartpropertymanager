<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccountBalances;
use App\Services\CurrentLandlord;

/*
 * What a landlord sees at a glance: how each invoice was paid, and what each
 * tenant / occupied unit owes (or has paid in advance).
 */

afterEach(fn () => CurrentLandlord::clear());

/** James in A1 with three 550,000 invoices: the deposit and October paid, November partly paid and overdue. */
function balancesSetup(): array
{
    $landlord = Landlord::factory()->create();
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::set($landlord->id);

    $unit = Unit::create(['name' => 'A1', 'estate_id' => Estate::create(['name' => 'Kyebando', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'James Kamoga', 'email' => 'james@example.test', 'primary_phone' => '+256783086794',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-00001', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01', 'rent_amount' => 550000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(),
    ]);
    $invoice = fn (string $number, string $status, string $due) => Invoice::create([
        'number' => $number, 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => "Rent {$number}", 'invoice_date' => $due, 'amount' => 550000, 'due_date' => $due, 'status' => $status,
    ]);
    $deposit = $invoice('INV-00001', 'paid', '2026-10-01');
    $october = $invoice('INV-00002', 'paid', '2026-10-01');
    $november = $invoice('INV-00003', 'partial', today()->subDay()->toDateString());

    $payment = fn (string $number, int $amount, string $status, string $ref) => Payment::create([
        'number' => $number, 'payable_type' => Tenant::class, 'payable_id' => $tenant->id, 'amount' => $amount,
        'payment_date' => '2026-11-03', 'payment_method' => 'mtn_mobile_money', 'reference' => $ref, 'status' => $status,
    ]);
    $small = $payment('PAY-00002', 200000, 'fully_allocated', 'RNT-AAA');
    $big = $payment('PAY-00003', 1500000, 'partially_allocated', 'RNT-BBB');
    PaymentAllocation::create(['payment_id' => $small->id, 'invoice_id' => $deposit->id, 'amount' => 200000]);
    PaymentAllocation::create(['payment_id' => $big->id, 'invoice_id' => $deposit->id, 'amount' => 350000]);
    PaymentAllocation::create(['payment_id' => $big->id, 'invoice_id' => $october->id, 'amount' => 550000]);
    PaymentAllocation::create(['payment_id' => $big->id, 'invoice_id' => $november->id, 'amount' => 450000]);   // 100,000 still owed; 150,000 credit left
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'unit', 'tenant', 'lease', 'deposit', 'november');
}

test('balances: owed and overdue from open invoices, credit from unallocated payments', function () {
    $s = balancesSetup();
    CurrentLandlord::set($s['landlord']->id);

    $expected = ['owed' => 100000.0, 'overdue' => 100000.0, 'credit' => 150000.0];
    expect(app(AccountBalances::class)->forTenants([$s['tenant']->id]))->toBe([$s['tenant']->id => $expected])
        ->and(app(AccountBalances::class)->forLeases([$s['lease']->id]))->toBe([$s['lease']->id => $expected]);
});

test('an invoice shows which payments paid it', function () {
    $s = balancesSetup();
    CurrentLandlord::set($s['landlord']->id);

    $trail = $s['deposit']->load('allocations.payment')->paymentTrail();
    expect(collect($trail)->pluck('number')->all())->toBe(['PAY-00002', 'PAY-00003'])
        ->and(collect($trail)->pluck('applied')->all())->toBe([200000.0, 350000.0])
        ->and($trail[0])->toMatchArray(['method' => 'Mtn Mobile Money', 'reference' => 'RNT-AAA', 'date' => 'Nov 03, 2026']);
    CurrentLandlord::clear();

    $this->actingAs($s['admin'])->get(route('invoices.index'))->assertOk()
        ->assertSee('How It Was Paid')->assertSee('See the payments for this invoice')->assertSee('PAY-00002')->assertSee('PAY-00003')->assertDontSee('Paid by');
});

test('the tenant and unit lists show what the tenant owes', function () {
    $s = balancesSetup();

    $this->actingAs($s['admin'])->get(route('tenants.index'))->assertOk()
        ->assertSee('UGX 100,000')->assertSee('UGX 100,000 overdue')->assertSee('UGX 150,000 credit');

    $this->actingAs($s['admin'])->get(route('units.index'))->assertOk()
        ->assertSee('James Kamoga')->assertSee('UGX 100,000 overdue')->assertSee('UGX 150,000 credit');
});
