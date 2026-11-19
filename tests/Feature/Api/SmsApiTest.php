<?php

use App\Models\PaymentTransaction;
use App\Models\SmsBroadcast;
use App\Models\SmsBundle;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Http;

/*
 * Mobile API, landlord phase 1d: SMS for admins (balance and bundles, the
 * log and resending, bulk SMS with a preview, buying SMS by mobile money).
 */

beforeEach(function () {
    config(['services.marz_sms.enabled' => true, 'services.marz_sms.api_key' => 'k', 'services.marz_sms.api_secret' => 's', 'services.marz_sms.base_url' => 'https://sms.test/api']);
    Http::fake(['sms.test/*' => Http::response(['status' => 'success'])]);
});
afterEach(fn () => CurrentLandlord::clear());

test('SMS is for admins: the overview shows the balance, bundles and what failed', function () {
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();
    $landlord->forceFill(['sms_balance' => 40])->save();
    SmsBundle::create(['name' => 'Starter', 'quantity' => 100, 'price' => 3500, 'is_active' => true]);
    $staff = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'user', 'password' => 'Secret-pass-123']);

    $this->withToken(apiToken($this, $staff))->getJson(route('api.v1.sms.overview'))->assertForbidden()->assertJsonPath('code', 'admin_only');
    $this->withToken(apiToken($this, $admin))->getJson(route('api.v1.sms.overview'))->assertOk()
        ->assertJsonPath('balance', 40)->assertJsonPath('bundles.0.quantity', 100)->assertJsonPath('max_length', 320)
        ->assertJsonFragment(['key' => '{first_name}']);
});

test('a failed SMS is resent from the log, once', function () {
    ['admin' => $admin, 'landlord' => $landlord, 'tenant' => $tenant] = apiLandlord();
    $landlord->forceFill(['sms_balance' => 10])->save();
    $log = CurrentLandlord::runAs($landlord->id, fn () => SmsLog::create(['tenant_id' => $tenant->id, 'phone' => '+256772000001', 'message' => 'Rent reminder',
        'notification_type' => 'invoice_reminder', 'status' => 'failed', 'error' => 'Network', 'segments' => 1]));
    CurrentLandlord::clear();
    $token = apiToken($this, $admin);

    $this->withToken($token)->getJson(route('api.v1.sms.logs', ['status' => 'failed']))->assertOk()->assertJsonPath('data.0.can_resend', true);
    $this->withToken($token)->postJson(route('api.v1.sms.resend', $log))->assertOk()->assertJsonPath('message', 'SMS resent to +256772000001.');
    $this->withToken($token)->postJson(route('api.v1.sms.resend', $log))->assertUnprocessable()->assertJsonValidationErrors('sms');
});

test('bulk SMS: the preview shows who it reaches, the credits and a personalised sample; sending needs enough credit', function () {
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();
    $token = apiToken($this, $admin);
    $message = 'Hi {first_name}, you owe {balance} for {unit}. {landlord}';

    $landlord->forceFill(['sms_balance' => 0])->save();
    $this->withToken($token)->postJson(route('api.v1.sms.broadcasts.preview'), ['message' => $message])->assertOk()
        ->assertJsonPath('recipients', 1)->assertJsonPath('credits', 1)->assertJsonPath('enough', false)
        ->assertJsonPath('sample', "Hi Grace, you owe UGX 500,000 for A4. {$landlord->name}");
    $this->withToken($token)->postJson(route('api.v1.sms.broadcasts.store'), ['message' => $message])->assertUnprocessable()->assertJsonValidationErrors('audience');

    $landlord->forceFill(['sms_balance' => 20])->save();
    $broadcast = $this->withToken($token)->postJson(route('api.v1.sms.broadcasts.store'), ['message' => $message, 'owing_only' => true])->assertCreated()->json();
    $this->withToken($token)->getJson(route('api.v1.sms.broadcasts.show', $broadcast['id']))->assertOk()
        ->assertJsonPath('status', 'completed')->assertJsonPath('sent', 1)->assertJsonPath('recipients.0.tenant', 'Grace Namata');
    expect(SmsBroadcast::withoutGlobalScopes()->count())->toBe(1);
});

test('buying SMS sends a mobile money prompt; its status is polled until the credits arrive', function () {
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    ['admin' => $admin, 'landlord' => $landlord] = apiLandlord();
    $bundle = SmsBundle::create(['name' => 'Starter', 'quantity' => 100, 'price' => 3500, 'is_active' => true]);
    $token = apiToken($this, $admin);

    $this->withToken($token)->postJson(route('api.v1.sms.buy'), ['bundle_id' => $bundle->id, 'phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    $buy = $this->withToken($token)->postJson(route('api.v1.sms.buy'), ['bundle_id' => $bundle->id, 'phone' => '0772123456'])->assertCreated()->json();

    $before = (int) $landlord->fresh()->sms_balance;
    $this->travel(6)->seconds();   // the status is checked with the gateway at most every 5 seconds
    $this->withToken($token)->getJson(route('api.v1.payments.status', $buy['reference']))->assertOk()
        ->assertJsonPath('status', PaymentTransaction::SUCCEEDED)->assertJsonPath('sms_balance', $before + 100);
});
