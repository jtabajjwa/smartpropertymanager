<?php

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\InvoicingService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function createLeaseWithDeps(array $overrides = []): Lease
{
    $user = User::first();


    $estate = \App\Models\Estate::create([
        'name' => 'Test Estate',
        'district' => 'Kampala',
        'created_by' => $user->id,
    ]);

    $unit = \App\Models\Unit::create([
        'name' => 'Unit A1',
        'estate_id' => $estate->id,
        'status' => 'vacant',
        'created_by' => $user->id,
    ]);

    $tenant = \App\Models\Tenant::create([
        'number' => 'TNT-' . str_pad(fake()->unique()->randomNumber(5), 5, '0', STR_PAD_LEFT),
        'type' => 'individual',
        'name' => 'Test Tenant',
        'email' => fake()->unique()->safeEmail(),
        'primary_phone' => fake()->unique()->e164PhoneNumber(),
        'id_type' => 'national_id',
        'id_number' => 'NIN-' . fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '+256700000000',
        'created_by' => $user->id,
    ]);

    return Lease::create(array_merge([
        'number' => 'LSE-' . str_pad(fake()->unique()->randomNumber(5), 5, '0', STR_PAD_LEFT),
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'rent_amount' => 500000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 0,
        'required_upfront_periods' => 1,
        'next_invoice_date' => '2026-02-01',
        'created_by' => $user->id,
    ], $overrides));
}

// --- InvoicingService ---

test('createRecurringInvoice creates invoice and advances next_invoice_date', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-02-01',
        'billing_cycle' => 'monthly',
        'rent_amount' => 500000,
        'required_upfront_periods' => 1,
    ]);

    $service = app(InvoicingService::class);
    $invoice = $service->createRecurringInvoice($lease);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->type)->toBe('rent');
    expect((float) $invoice->amount)->toBe(500000.00);
    expect($invoice->due_date->format('Y-m-d'))->toBe('2026-02-01');
    expect($invoice->status)->toBe('unpaid');
    expect($invoice->number)->toStartWith('INV-');

    // next_invoice_date should advance to March
    expect($lease->fresh()->next_invoice_date->format('Y-m-d'))->toBe('2026-03-01');
});

test('recurring invoice title shows period range', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-02-01',
        'billing_cycle' => 'monthly',
        'required_upfront_periods' => 1,
    ]);

    $invoice = app(InvoicingService::class)->createRecurringInvoice($lease);

    expect($invoice->title)->toContain('Rent');
    expect($invoice->title)->toContain('01 Feb 2026');
    expect($invoice->title)->toContain('28 Feb 2026');
});

test('recurring invoice amount respects required_upfront_periods', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-02-01',
        'billing_cycle' => 'monthly',
        'rent_amount' => 300000,
        'required_upfront_periods' => 3,
    ]);

    $invoice = app(InvoicingService::class)->createRecurringInvoice($lease);

    // 300000 * 3 = 900000
    expect((float) $invoice->amount)->toBe(900000.00);

    // next_invoice_date should advance by 3 months
    expect($lease->fresh()->next_invoice_date->format('Y-m-d'))->toBe('2026-05-01');
});

// --- GenerateRecurringInvoices Command ---

test('command generates invoices for leases due today', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => today()->format('Y-m-d'),
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
    expect($lease->fresh()->next_invoice_date->gt(today()))->toBeTrue();
});

test('command generates invoices for past-due leases (catch-up)', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => today()->subMonths(3)->format('Y-m-d'),
        'billing_cycle' => 'monthly',
        'required_upfront_periods' => 1,
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    // Should have generated 3 or 4 invoices to catch up
    $count = Invoice::where('lease_id', $lease->id)->count();
    expect($count)->toBeGreaterThanOrEqual(3);
    expect($lease->fresh()->next_invoice_date->gt(today()))->toBeTrue();
});

test('command skips leases with future next_invoice_date', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => today()->addMonth()->format('Y-m-d'),
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

test('command skips terminated leases', function () {
    $lease = createLeaseWithDeps([
        'status' => 'terminated',
        'next_invoice_date' => today()->format('Y-m-d'),
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

test('command skips expired leases', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => today()->format('Y-m-d'),
        'end_date' => today()->subDay()->format('Y-m-d'),
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

test('command processes multiple leases independently', function () {
    $lease1 = createLeaseWithDeps([
        'next_invoice_date' => today()->format('Y-m-d'),
    ]);
    $lease2 = createLeaseWithDeps([
        'next_invoice_date' => today()->format('Y-m-d'),
    ]);

    $this->artisan('invoices:generate-recurring')
        ->assertSuccessful();

    expect(Invoice::where('lease_id', $lease1->id)->count())->toBe(1);
    expect(Invoice::where('lease_id', $lease2->id)->count())->toBe(1);
});

// --- Billing Cycle Variations ---

test('weekly billing cycle advances by weeks', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-02-01',
        'billing_cycle' => 'weekly',
        'required_upfront_periods' => 1,
    ]);

    app(InvoicingService::class)->createRecurringInvoice($lease);

    expect($lease->fresh()->next_invoice_date->format('Y-m-d'))->toBe('2026-02-08');
});

test('quarterly billing cycle advances by 3 months', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-01-01',
        'billing_cycle' => 'quarterly',
        'required_upfront_periods' => 1,
    ]);

    app(InvoicingService::class)->createRecurringInvoice($lease);

    expect($lease->fresh()->next_invoice_date->format('Y-m-d'))->toBe('2026-04-01');
});

test('yearly billing cycle advances by 1 year', function () {
    $lease = createLeaseWithDeps([
        'next_invoice_date' => '2026-01-01',
        'billing_cycle' => 'yearly',
        'required_upfront_periods' => 1,
    ]);

    app(InvoicingService::class)->createRecurringInvoice($lease);

    expect($lease->fresh()->next_invoice_date->format('Y-m-d'))->toBe('2027-01-01');
});
