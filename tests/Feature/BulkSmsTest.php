<?php

use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\SmsBroadcast;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Http;

/*
 * Bulk SMS: a landlord writes one message with placeholders, picks who gets it
 * (all current tenants / some estates / only those who owe), and it is sent in
 * the background from their SMS credits.
 */

beforeEach(function () {
    config(['services.marz_sms.enabled' => true, 'services.marz_sms.api_key' => 'k', 'services.marz_sms.api_secret' => 's', 'services.marz_sms.base_url' => 'https://sms.test/api']);
    Http::fake(['sms.test/*' => Http::response(['status' => 'success'])]);
});
afterEach(fn () => CurrentLandlord::clear());

/** Kato Properties: Grace (Kisaasi A1, owes 300,000), Peter (Ntinda B2, paid up), Ann (Ntinda B3, no phone), Old (lease ended). */
function bulkSetup(int $credits = 100): array
{
    $landlord = Landlord::factory()->create(['name' => 'Kato Properties', 'sms_balance' => $credits]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::set($landlord->id);

    $kisaasi = Estate::create(['name' => 'Kisaasi Court', 'district' => 'Kampala']);
    $ntinda = Estate::create(['name' => 'Ntinda Flats', 'district' => 'Kampala']);
    $n = 0;
    $tenant = function (string $name, ?string $phone, Estate $estate, string $unit, string $status = 'active') use (&$n) {
        $n++;
        $t = Tenant::create([
            'type' => 'individual', 'name' => $name, 'email' => "t{$n}@example.test", 'primary_phone' => $phone,
            'id_type' => 'national_id', 'id_number' => "NIN-{$n}", 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
        ]);
        $u = Unit::create(['name' => $unit, 'estate_id' => $estate->id, 'status' => 'occupied']);
        $lease = Lease::create([
            'number' => "LSE-0000{$n}", 'tenant_id' => $t->id, 'unit_id' => $u->id, 'status' => $status, 'start_date' => '2026-10-01', 'rent_amount' => 300000,
            'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(),
        ]);

        return [$t, $lease];
    };

    [$grace, $graceLease] = $tenant('Grace Namata', '0772111222', $kisaasi, 'A1');
    [$peter] = $tenant('Peter Okello', '+256701333444', $ntinda, 'B2');
    $tenant('Ann Nabirye', '12345', $ntinda, 'B3');
    $tenant('Old Tenant', '0772999000', $ntinda, 'B4', 'terminated');

    Invoice::create([
        'number' => 'INV-00001', 'lease_id' => $graceLease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $graceLease->id,
        'type' => 'rent', 'title' => 'Rent', 'invoice_date' => today()->subDays(5), 'amount' => 300000, 'due_date' => today()->subDay(), 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'kisaasi', 'ntinda', 'grace', 'peter');
}

test('the compose page lists current tenants with a phone, with their details for the preview', function () {
    ['admin' => $admin] = bulkSetup();

    $this->actingAs($admin)->get(route('sms-broadcasts.create'))->assertOk()
        ->assertSee('Grace Namata')->assertSee('Peter Okello')->assertDontSee('Old Tenant')
        ->assertSee('1 current tenant has no valid phone number');
    $this->actingAs($admin)->get(route('sms-broadcasts.index'))->assertOk()->assertSee('No bulk SMS yet');
});

test('a bulk SMS goes to every current tenant, personalised, and is paid from SMS credit', function () {
    ['admin' => $admin, 'landlord' => $landlord, 'grace' => $grace] = bulkSetup(100);

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), [
        'message' => 'Dear {first_name}, your balance for {unit} at {estate} is {balance}. - {landlord}',
    ])->assertRedirect()->assertSessionHas('success');

    $b = SmsBroadcast::withoutGlobalScopes()->sole();
    expect($b->status)->toBe('completed')->and($b->recipients_count)->toBe(2)->and($b->sent_count)->toBe(2)->and($b->credits_used)->toBe(2);

    $logs = SmsLog::withoutGlobalScopes()->where('reference_type', 'SmsBroadcast')->where('reference_id', $b->id)->get()->keyBy('tenant_id');
    expect($logs)->toHaveCount(2)
        ->and($logs[$grace->id]->message)->toBe('Dear Grace, your balance for A1 at Kisaasi Court is UGX 300,000. - Kato Properties')
        ->and($logs[$grace->id]->notification_type)->toBe('bulk')
        ->and($logs[$grace->id]->phone)->toBe('256772111222')
        ->and($landlord->fresh()->sms_balance)->toBe(98);
    Http::assertSentCount(2);

    $this->actingAs($admin)->get(route('sms-broadcasts.show', $b))->assertOk()->assertSee('Dear Grace, your balance for A1')->assertSee('Peter Okello');
    $this->actingAs($admin)->get(route('sms-broadcasts.index'))->assertOk()->assertSee('2</span> / 2 sent', false);
});

test('a bulk SMS can go to some estates only, or only to tenants who owe', function () {
    ['admin' => $admin, 'ntinda' => $ntinda, 'peter' => $peter, 'grace' => $grace] = bulkSetup();

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Water off on Friday at {estate}.', 'estates' => [$ntinda->id]])->assertSessionHas('success');
    expect(SmsLog::withoutGlobalScopes()->pluck('tenant_id')->all())->toBe([$peter->id]);

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Hi {first_name}, you owe {balance}.', 'owing_only' => 1])->assertSessionHas('success');
    expect(SmsLog::withoutGlobalScopes()->latest('id')->first()->tenant_id)->toBe($grace->id)
        ->and(SmsBroadcast::withoutGlobalScopes()->latest('id')->first()->audienceLabel())->toBe('All current tenants · only tenants who owe');

    // Nobody matches: Ntinda tenants who owe.
    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Hello there', 'estates' => [$ntinda->id], 'owing_only' => 1])->assertSessionHasErrors('audience');
});

test('a bulk SMS is refused without enough SMS credit, and nothing is sent', function () {
    ['admin' => $admin] = bulkSetup(1);

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Dear {first_name}, hello.'])->assertSessionHasErrors('audience');
    expect(SmsBroadcast::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('landlords only reach their own tenants and estates; non-admins cannot send', function () {
    ['admin' => $admin] = bulkSetup();
    ['kisaasi' => $otherEstate, 'admin' => $otherAdmin] = bulkSetup();

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Hello there', 'estates' => [$otherEstate->id]])->assertSessionHasErrors('estates.0');

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Hello everyone']);
    $mine = SmsBroadcast::withoutGlobalScopes()->sole();
    $this->post(route('logout'));
    CurrentLandlord::clear(); // a new request starts with no landlord (static state outlives requests in tests)
    $this->actingAs($otherAdmin)->get(route('sms-broadcasts.show', $mine))->assertNotFound();

    $agent = User::factory()->create(['landlord_id' => $otherAdmin->landlord_id, 'role' => 'user']);
    $this->post(route('logout'));
    $this->actingAs($agent)->get(route('sms-broadcasts.create'))->assertForbidden();
});

test('a broadcast is only ever sent once', function () {
    ['admin' => $admin, 'landlord' => $landlord] = bulkSetup();

    $this->actingAs($admin)->post(route('sms-broadcasts.store'), ['message' => 'Hello everyone']);
    $b = SmsBroadcast::withoutGlobalScopes()->sole();

    (new \App\Jobs\SendSmsBroadcast($b->id, $landlord->id))->handle(app(\App\Services\SmsBroadcasts::class), app(\App\Services\SmsNotificationService::class));
    expect(SmsLog::withoutGlobalScopes()->count())->toBe(2);
    Http::assertSentCount(2);
});
