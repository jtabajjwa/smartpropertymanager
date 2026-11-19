<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;

/*
 * Shared by the mobile API tests (tests/Feature/Api): a landlord with data,
 * and signing in for a token. Loaded from tests/Pest.php.
 */

/** A landlord who accepted the terms, with an admin and Grace renting A4 (500,000 unpaid). */
function apiLandlord(array $userAttributes = []): array
{
    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing', 'current_period_end' => now()->addDays(20)]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id, 'email' => 'owner'.$landlord->id.'@homes.test', 'password' => 'Secret-pass-123'] + $userAttributes);
    $landlord->acceptTerms($admin, '127.0.0.1');

    CurrentLandlord::set($landlord->id);
    $unit = Unit::create(['name' => 'A4', 'estate_id' => Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create(['number' => 'TEN-'.$landlord->id, 'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace'.$landlord->id.'@example.test',
        'primary_phone' => '+2567720000'.str_pad((string) $landlord->id, 2, '0', STR_PAD_LEFT), 'id_type' => 'national_id', 'id_number' => 'N'.$landlord->id,
        'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000']);
    $lease = Lease::create(['number' => 'LSE-'.$landlord->id, 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01',
        'rent_amount' => 500000, 'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => '2026-12-01']);
    $invoice = Invoice::create(['number' => 'INV-'.$landlord->id, 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'November rent', 'invoice_date' => '2026-11-01', 'amount' => 500000, 'due_date' => '2026-11-05', 'status' => 'unpaid']);
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'tenant', 'lease', 'invoice', 'unit');
}

function apiToken($test, User $user, string $password = 'Secret-pass-123'): string
{
    $token = $test->postJson(route('api.v1.login'), ['email' => $user->email, 'password' => $password, 'device_name' => 'Pixel 8'])->assertOk()->json('token');

    // Like a real request: nothing carries over from signing in (the API must set the landlord itself).
    CurrentLandlord::clear();
    app('auth')->forgetGuards();

    return $token;
}
