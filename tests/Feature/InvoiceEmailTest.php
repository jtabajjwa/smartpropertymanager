<?php

use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Mail;

function makeLeaseWithTenantEmail(int $landlordId, ?string $tenantEmail): Lease
{
    CurrentLandlord::set($landlordId);


    $estate = Estate::create([
        'name' => 'Test Estate',
        'district' => 'Kampala',
    ]);

    $unit = Unit::create([
        'name' => 'Unit B2',
        'estate_id' => $estate->id,
        'status' => 'occupied',
    ]);

    $tenant = Tenant::create([
        'type' => 'individual',
        'name' => 'Sam Tenant',
        'email' => $tenantEmail,
        'primary_phone' => fake()->unique()->e164PhoneNumber(),
        'id_type' => 'national_id',
        'id_number' => 'NIN-' . fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency Contact',
        'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '+256700000000',
    ]);

    $lease = Lease::create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'rent_amount' => 500000,
        'billing_cycle' => 'monthly',
        'security_deposit' => 0,
        'required_upfront_periods' => 1,
        'next_invoice_date' => today()->format('Y-m-d'),
        'number' => 'LSE-EMAIL-' . fake()->unique()->randomNumber(6),
    ]);

    CurrentLandlord::clear();

    return $lease;
}

test('creating an invoice with send_email sends a PDF invoice to the tenant', function () {
    Mail::fake();

    $user = User::factory()->create();
    $lease = makeLeaseWithTenantEmail($user->landlord_id, 'tenant@example.com');

    $this->actingAs($user)
        ->post(route('invoices.store'), [
            'lease_id' => $lease->id,
            'type' => 'rent',
            'title' => 'September Rent',
            'invoice_date' => '2026-09-01',
            'amount' => 500000,
            'due_date' => '2026-09-05',
            'send_email' => '1',
        ])->assertRedirect()->assertSessionHas('success');

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('tenant@example.com') && $mail->payerName === 'Sam Tenant';
    });
});

test('creating an invoice without send_email does not send an email', function () {
    Mail::fake();

    $user = User::factory()->create();
    $lease = makeLeaseWithTenantEmail($user->landlord_id, 'tenant2@example.com');

    $this->actingAs($user)
        ->post(route('invoices.store'), [
            'lease_id' => $lease->id,
            'type' => 'rent',
            'title' => 'September Rent',
            'invoice_date' => '2026-09-01',
            'amount' => 500000,
            'due_date' => '2026-09-05',
        ])->assertRedirect()->assertSessionHas('success');

    Mail::assertNothingSent();
});

test('invoice email is skipped gracefully when the payer has no email', function () {
    Mail::fake();

    $user = User::factory()->create();

    CurrentLandlord::set($user->landlord_id);
    $customer = Customer::create(['name' => 'No Email Customer', 'phone' => '+256700111222', 'email' => null]);
    CurrentLandlord::clear();

    $this->actingAs($user)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'type' => 'utility',
            'title' => 'Water Bill',
            'invoice_date' => '2026-09-01',
            'amount' => 30000,
            'due_date' => '2026-09-05',
            'send_email' => '1',
        ])->assertRedirect()->assertSessionHas('success');

    Mail::assertNothingSent();
});

test('creating a customer invoice with send_email sends to the customer', function () {
    Mail::fake();

    $user = User::factory()->create();

    CurrentLandlord::set($user->landlord_id);
    $customer = Customer::create(['name' => 'Walk-in Customer', 'phone' => '+256700333444', 'email' => 'customer@example.com']);
    CurrentLandlord::clear();

    $this->actingAs($user)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'type' => 'utility',
            'title' => 'Electricity Bill',
            'invoice_date' => '2026-09-01',
            'amount' => 45000,
            'due_date' => '2026-09-05',
            'send_email' => '1',
        ])->assertRedirect()->assertSessionHas('success');

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('customer@example.com') && $mail->payerName === 'Walk-in Customer';
    });
});

// --- View / Download PDF ---

test('user can view and download an invoice pdf', function () {
    $user = User::factory()->create();
    $lease = makeLeaseWithTenantEmail($user->landlord_id, 'tenant3@example.com');

    $this->actingAs($user)->post(route('invoices.store'), [
        'lease_id' => $lease->id,
        'type' => 'rent',
        'title' => 'September Rent',
        'invoice_date' => '2026-09-01',
        'amount' => 500000,
        'due_date' => '2026-09-05',
    ]);

    $invoice = Invoice::where('title', 'September Rent')->first();

    $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('cannot view another landlord\'s invoice pdf', function () {
    $user = User::factory()->create();

    $otherLandlord = Landlord::factory()->create();
    $otherLease = makeLeaseWithTenantEmail($otherLandlord->id, 'other@example.com');

    CurrentLandlord::set($otherLandlord->id);
    $otherInvoice = Invoice::create([
        'lease_id' => $otherLease->id,
        'invoiceable_type' => Lease::class,
        'invoiceable_id' => $otherLease->id,
        'type' => 'rent',
        'title' => 'Other Landlord Rent',
        'invoice_date' => '2026-09-01',
        'amount' => 500000,
        'due_date' => '2026-09-05',
    ]);
    CurrentLandlord::clear();

    $this->actingAs($user)
        ->get(route('invoices.pdf', $otherInvoice))
        ->assertNotFound();
});

// --- Resend Email ---

test('user can resend an invoice email', function () {
    Mail::fake();

    $user = User::factory()->create();
    $lease = makeLeaseWithTenantEmail($user->landlord_id, 'resend@example.com');

    $this->actingAs($user)->post(route('invoices.store'), [
        'lease_id' => $lease->id,
        'type' => 'rent',
        'title' => 'Resend Test Rent',
        'invoice_date' => '2026-09-01',
        'amount' => 500000,
        'due_date' => '2026-09-05',
    ]);

    $invoice = Invoice::where('title', 'Resend Test Rent')->first();

    Mail::assertNothingSent();

    $this->actingAs($user)
        ->post(route('invoices.send-email', $invoice))
        ->assertRedirect()->assertSessionHas('success');

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('resend@example.com');
    });
});

test('resending fails gracefully with a flash error when the payer has no email', function () {
    Mail::fake();

    $user = User::factory()->create();

    CurrentLandlord::set($user->landlord_id);
    $customer = Customer::create(['name' => 'No Email Resend Customer', 'phone' => '+256700555666', 'email' => null]);
    $invoice = Invoice::create([
        'invoiceable_type' => Customer::class,
        'invoiceable_id' => $customer->id,
        'type' => 'utility',
        'title' => 'No Email Water Bill',
        'invoice_date' => '2026-09-01',
        'amount' => 30000,
        'due_date' => '2026-09-05',
    ]);
    CurrentLandlord::clear();

    $this->actingAs($user)
        ->post(route('invoices.send-email', $invoice))
        ->assertRedirect()->assertSessionHas('error');

    Mail::assertNothingSent();
});

test('cannot resend email for another landlord\'s invoice', function () {
    $user = User::factory()->create();

    $otherLandlord = Landlord::factory()->create();
    $otherLease = makeLeaseWithTenantEmail($otherLandlord->id, 'other2@example.com');

    CurrentLandlord::set($otherLandlord->id);
    $otherInvoice = Invoice::create([
        'lease_id' => $otherLease->id,
        'invoiceable_type' => Lease::class,
        'invoiceable_id' => $otherLease->id,
        'type' => 'rent',
        'title' => 'Cross Tenant Rent',
        'invoice_date' => '2026-09-01',
        'amount' => 500000,
        'due_date' => '2026-09-05',
    ]);
    CurrentLandlord::clear();

    $this->actingAs($user)
        ->post(route('invoices.send-email', $otherInvoice))
        ->assertNotFound();
});
