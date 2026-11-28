<?php

use App\Actions\CreateInvoice;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;

/*
 * Every invoice is numbered from the landlord's own counter, skipping numbers
 * already used. Manual invoices used to be numbered from the global invoice id,
 * which later clashed with the counter (duplicate INV-00004 on creating a lease).
 */

afterEach(fn () => CurrentLandlord::clear());

function numberingLease(): Lease
{
    $landlord = Landlord::factory()->create();
    CurrentLandlord::set($landlord->id);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '0772111222',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $unit = Unit::create(['name' => 'A1', 'estate_id' => Estate::create(['name' => 'Kisaasi Court', 'district' => 'Kampala'])->id, 'status' => 'occupied']);

    return Lease::create([
        'number' => 'LSE-00001', 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01', 'rent_amount' => 300000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(),
    ]);
}

function oldStyleInvoice(Lease $lease, string $number): Invoice
{
    return Invoice::create([
        'number' => $number, 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'Rent', 'invoice_date' => today(), 'amount' => 300000, 'due_date' => today(), 'status' => 'unpaid',
    ]);
}

test('the invoice counter skips numbers an older invoice already has', function () {
    $lease = numberingLease();
    oldStyleInvoice($lease, 'INV-00002');
    oldStyleInvoice($lease, 'INV-00003');

    $next = fn () => DB::transaction(fn () => app(NumberGenerator::class)->next($lease->landlord_id, 'invoice', 'INV', table: 'invoices'));

    expect($next())->toBe('INV-00001')->and($next())->toBe('INV-00004')->and($next())->toBe('INV-00005');
});

test('manual invoices use the landlord\'s counter, so they never clash with lease invoices', function () {
    $lease = numberingLease();
    $data = fn () => ['lease_id' => $lease->id, 'type' => 'rent', 'title' => 'Rent', 'invoice_date' => today()->toDateString(), 'amount' => 300000, 'due_date' => today()->toDateString(), 'status' => 'unpaid'];

    $first = app(CreateInvoice::class)($data());
    DB::transaction(fn () => app(NumberGenerator::class)->next($lease->landlord_id, 'invoice', 'INV', table: 'invoices')); // a lease invoice takes the next one
    $third = app(CreateInvoice::class)($data());

    expect($first->number)->toBe('INV-00001')->and($third->number)->toBe('INV-00003');
});
