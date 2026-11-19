<?php

use App\Models\CollectionFeeTier;
use App\Models\DeviceToken;
use App\Models\Issue;
use App\Models\Tenant;
use App\Payments\Msisdn;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Push notifications to the mobile app through Firebase Cloud Messaging
 * (faked): phones register after signing in, signing out stops them, and
 * each event reaches the right people with the screen to open.
 */

beforeEach(function () {
    // A test-only key stands in for the service account's, so the OAuth sign-in is really signed.
    $pem = file_get_contents(base_path('tests/Fixtures/payments/yo-test-private.pem'));
    $this->fcmKeyFile = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($this->fcmKeyFile, json_encode(['client_email' => 'push@primora-test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
    config(['services.fcm.project_id' => 'primora-test', 'services.fcm.credentials' => $this->fcmKeyFile]);

    $this->pushes = [];
    $this->goneTokens = [];
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]);
        }
        if (str_contains($request->url(), 'fcm.googleapis.com/v1/projects/primora-test/messages:send')) {
            $message = $request->data()['message'];
            if (in_array($message['token'], $this->goneTokens, true)) {
                return Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404);
            }
            $this->pushes[] = $message;

            return Http::response(['name' => 'projects/primora-test/messages/1']);
        }

        return Http::response('', 200);
    });
    $this->mock(SmsService::class, fn ($mock) => $mock->shouldReceive('send')->andReturn(true));
    Mail::fake();
});
afterEach(function () {
    CurrentLandlord::clear();
    @unlink($this->fcmKeyFile);
});

/** A tenant's app token, as after signing in with an SMS code. */
function pushTenantToken(Tenant $tenant): string
{
    return $tenant->createToken('Tecno', ['tenant', 'signin:sms:'.Msisdn::normalize($tenant->primary_phone)])->plainTextToken;
}

function pushReset($test): void
{
    CurrentLandlord::clear();
    app('auth')->forgetGuards();
    $test->flushHeaders();
}

test('phones register for notifications, change hands, and stop when signed out', function () {
    ['admin' => $admin, 'tenant' => $tenant] = apiLandlord();
    $landlordToken = apiToken($this, $admin);

    $this->withToken($landlordToken)->postJson(route('api.v1.auth.devices.store'), ['token' => 'phone-A', 'platform' => 'android'])->assertOk();
    $device = DeviceToken::sole();
    expect($device->owner_type)->toBe(\App\Models\User::class)->and($device->owner_id)->toBe($admin->id)->and($device->access_token_id)->not->toBeNull();
    pushReset($this);

    // The same phone signs in as the tenant: the token moves to them.
    $tenantToken = pushTenantToken($tenant);
    $this->withToken($tenantToken)->postJson(route('api.v1.tenant.devices.store'), ['token' => 'phone-A', 'platform' => 'android'])->assertOk();
    expect(DeviceToken::count())->toBe(1)->and(DeviceToken::sole()->owner_type)->toBe(Tenant::class);
    pushReset($this);

    $this->withToken($tenantToken)->postJson(route('api.v1.tenant.auth.logout'))->assertOk();
    expect(DeviceToken::count())->toBe(0);
});

test('tenants hear about new bills, payments and replies to their issues; landlords about new issues and notes', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'lease' => $lease, 'landlord' => $landlord] = apiLandlord();
    $landlordToken = apiToken($this, $admin);
    $this->withToken($landlordToken)->postJson(route('api.v1.auth.devices.store'), ['token' => 'landlord-phone'])->assertOk();
    pushReset($this);
    $tenantToken = pushTenantToken($tenant);
    $this->withToken($tenantToken)->postJson(route('api.v1.tenant.devices.store'), ['token' => 'tenant-phone'])->assertOk();
    pushReset($this);

    // The landlord raises a bill and records a payment.
    $this->withToken($landlordToken)->postJson(route('api.v1.invoices.store'), ['lease_id' => $lease->id, 'type' => 'utility', 'title' => 'Water, November', 'invoice_date' => '2026-11-10', 'due_date' => '2026-11-20', 'amount' => 20000])->assertCreated();
    pushReset($this);
    $this->withToken($landlordToken)->postJson(route('api.v1.payments.store'), ['tenant_id' => $tenant->id, 'amount' => 20000, 'payment_date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();
    pushReset($this);

    expect($this->pushes)->toHaveCount(2)
        ->and($this->pushes[0]['token'])->toBe('tenant-phone')
        ->and($this->pushes[0]['notification']['title'])->toBe('New bill: Water, November')
        ->and($this->pushes[0]['data']['route'])->toBe('/t/bills')
        ->and($this->pushes[1]['notification']['body'])->toContain('UGX 20,000 received');

    // The tenant reports an issue: the landlord's phone hears about it.
    $id = $this->withToken($tenantToken)->postJson(route('api.v1.tenant.issues.store'), ['category' => 'repair', 'title' => 'Broken tap', 'description' => 'Bathroom tap drips.'])->assertCreated()->json('id');
    pushReset($this);
    expect(end($this->pushes))->token->toBe('landlord-phone')
        ->and(end($this->pushes)['notification']['title'])->toBe('New issue: Broken tap')
        ->and(end($this->pushes)['data']['route'])->toBe("/issues/{$id}");

    // The landlord replies; the tenant's phone opens that issue.
    $this->withToken($landlordToken)->postJson(route('api.v1.issues.respond', $id), ['status' => 'in_progress', 'note' => 'Plumber coming Friday'])->assertOk();
    pushReset($this);
    expect(end($this->pushes))->token->toBe('tenant-phone')
        ->and(end($this->pushes)['notification']['title'])->toBe('Broken tap: In progress')
        ->and(end($this->pushes)['notification']['body'])->toBe('Plumber coming Friday')
        ->and(end($this->pushes)['data']['route'])->toBe("/t/issues/{$id}");

    // The tenant's follow-up note goes back to the landlord.
    Issue::withoutGlobalScopes()->whereKey($id)->update(['status' => 'resolved']);
    $this->withToken($tenantToken)->postJson(route('api.v1.tenant.issues.comment', $id), ['note' => 'Still dripping'])->assertOk();
    expect(end($this->pushes)['notification']['title'])->toBe('Re-opened: Broken tap');
});

test('a tenant paying by mobile money tells the landlord, and uninstalled phones are forgotten', function () {
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1000]);
    ['admin' => $admin, 'tenant' => $tenant, 'invoice' => $invoice, 'landlord' => $landlord] = apiLandlord();
    $landlord->update(['collect_payments' => true]);
    $landlordToken = apiToken($this, $admin);
    $this->withToken($landlordToken)->postJson(route('api.v1.auth.devices.store'), ['token' => 'landlord-phone'])->assertOk();
    $this->withToken($landlordToken)->postJson(route('api.v1.auth.devices.store'), ['token' => 'old-landlord-phone'])->assertOk();
    pushReset($this);
    $this->goneTokens = ['old-landlord-phone'];

    $tenantToken = pushTenantToken($tenant);
    $ref = $this->withToken($tenantToken)->postJson(route('api.v1.tenant.pay.store'), ['invoice_id' => $invoice->id, 'amount' => 500000, 'phone' => $tenant->primary_phone])->assertCreated()->json('reference');
    $this->travel(6)->seconds();
    $this->withToken($tenantToken)->getJson(route('api.v1.tenant.pay.status', $ref))->assertOk()->assertJsonPath('status', 'succeeded');

    $toLandlord = collect($this->pushes)->firstWhere('token', 'landlord-phone');
    expect($toLandlord['notification']['title'])->toBe('Grace Namata paid UGX 500,000')
        ->and($toLandlord['notification']['body'])->toContain($invoice->number)
        ->and($toLandlord['data']['route'])->toStartWith('/payments/')
        ->and(DeviceToken::pluck('token')->all())->toBe(['landlord-phone']);
});

test('nothing is sent when Firebase is not set up, or to a phone whose sign-in was revoked', function () {
    ['admin' => $admin, 'tenant' => $tenant, 'lease' => $lease] = apiLandlord();
    $landlordToken = apiToken($this, $admin);
    $tenantToken = pushTenantToken($tenant);
    $this->withToken($tenantToken)->postJson(route('api.v1.tenant.devices.store'), ['token' => 'tenant-phone'])->assertOk();
    pushReset($this);

    config(['services.fcm.credentials' => null]);
    $this->withToken($landlordToken)->postJson(route('api.v1.payments.store'), ['tenant_id' => $tenant->id, 'amount' => 1000, 'payment_date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();
    pushReset($this);
    expect($this->pushes)->toBeEmpty();

    config(['services.fcm.credentials' => $this->fcmKeyFile]);
    $tenant->tokens()->delete();
    $this->withToken($landlordToken)->postJson(route('api.v1.payments.store'), ['tenant_id' => $tenant->id, 'amount' => 1000, 'payment_date' => '2026-11-10', 'payment_method' => 'cash'])->assertCreated();
    expect($this->pushes)->toBeEmpty();
});
