<?php

use App\Accounting\Ledger;
use App\Actions\RespondToIssue;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\PaymentTransaction;
use App\Models\SmsLog;
use App\Models\StaffUser;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\WhatsAppBundle;
use App\Models\WhatsAppCreditTransaction;
use App\Services\CurrentLandlord;
use App\Services\SmsCredits;
use App\Services\SmsNotificationService;
use App\Services\SmsService;
use App\Services\WhatsAppCredits;
use App\Support\Settings;
use App\WhatsApp\Templates;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * WhatsApp alerts (phase 2): tenant alerts go by WhatsApp template when the
 * landlord, tenant and template allow it, paid from WhatsApp credit, and fall
 * back to SMS (never both) when they can't be delivered.
 */

beforeEach(function () {
    config([
        'services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '1234567890',
        'services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me',
        'services.whatsapp.business_account_id' => '5550001',
        'payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0,
    ]);
    $this->metaAccepts = true;
    Http::fake(function (HttpRequest $request) {
        return match (true) {
            str_ends_with($request->url(), '/media') => Http::response(['id' => 'media-1']),
            str_ends_with($request->url(), '/messages') && ($request['type'] ?? null) === 'template' => $this->metaAccepts
                ? Http::response(['messages' => [['id' => 'wamid.alert.'.uniqid()]]])
                : Http::response(['error' => ['message' => '(#131030) Recipient phone number not in allowed list', 'code' => 131030]], 400),
            default => Http::response(['messages' => [['id' => 'wamid.bot.'.uniqid()]]]),
        };
    });
    foreach (array_keys(Templates::all()) as $name) {
        Templates::setStatus($name, 'APPROVED');
    }
    $this->sentSms = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sentSms[] = [$phone, $message];

            return true;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

/** Grace (0772 123 456, agreed to WhatsApp) renting A4 from Kololo Homes, who sends alerts WhatsApp first. */
function alertTenancy(string $mode = 'whatsapp_first', bool $agreed = true, int $waCredit = 5): array
{
    $landlord = Landlord::factory()->create(['name' => 'Kololo Homes', 'trading_name' => 'Kololo Homes Ltd']);
    $landlord->forceFill(['alert_channels' => $mode])->save();
    app(SmsCredits::class)->add($landlord->id, 20, 'purchase', 'setup');
    if ($waCredit) {
        app(WhatsAppCredits::class)->add($landlord->id, $waCredit, 'purchase', 'setup');
    }
    CurrentLandlord::set($landlord->id);
    $estate = Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'A4', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '0772 123 456',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'Kin',
        'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $tenant->setWhatsAppConsent($agreed);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(), 'number' => 'LSE-00001',
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-00001', 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'October rent', 'invoice_date' => '2026-10-01', 'amount' => 500000, 'due_date' => today()->addDays(5)->toDateString(), 'status' => 'unpaid',
    ]);

    return ['landlord' => $landlord->fresh(), 'tenant' => $tenant->fresh(), 'invoice' => $invoice, 'lease' => $lease, 'unit' => $unit];
}

function alertLogs()
{
    return SmsLog::withoutGlobalScopes()->orderBy('id')->get();
}

function templateRequests(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && ($r['type'] ?? null) === 'template')->values()->all();
}

/** Meta reporting on a message we sent, signed as Meta does. */
function metaStatus($test, string $wamid, string $status, array $errors = [])
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'statuses' => [array_filter(['id' => $wamid, 'status' => $status, 'recipient_id' => '256772123456', 'errors' => $errors ?: null])],
    ]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();
}

// --- Which way an alert goes -----------------------------------------------------------

test('a new invoice goes by WhatsApp with its PDF, uses 1 WhatsApp credit and no SMS', function () {
    $t = alertTenancy();

    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);

    $log = alertLogs()->sole();
    expect($log->channel)->toBe('whatsapp')->and($log->status)->toBe('sent')->and($log->wamid)->toStartWith('wamid.alert.')
        ->and($log->phone)->toBe('256772123456')->and($log->credits_charged)->toBe(1)
        ->and($t['landlord']->fresh()->whatsapp_balance)->toBe(4)
        ->and($t['landlord']->fresh()->sms_balance)->toBe(20)
        ->and($this->sentSms)->toBe([]);

    $sent = templateRequests()[0];
    $components = collect($sent['template']['components']);
    expect($sent['template']['name'])->toBe('primora_invoice_issued')
        ->and($components->firstWhere('type', 'header')['parameters'][0]['document'])->toBe(['id' => 'media-1', 'filename' => 'invoice-INV-00001.pdf'])
        ->and(collect($components->firstWhere('type', 'body')['parameters'])->pluck('text')->all())
            ->toBe(['Grace Namata', 'Kololo Homes Ltd', 'INV-00001', 'October rent', 'UGX 500,000', 'UGX 500,000', today()->addDays(5)->format('j M Y')])
        ->and($components->where('type', 'button')->pluck('parameters.0.payload')->values()->all())->toBe(['pay', Templates::STOP]);
    expect(WhatsAppCreditTransaction::where('type', 'usage')->sole()->sms_log_id)->toBe($log->id);
});

test('alerts go by SMS when the landlord chose only SMS, the tenant has not agreed, or the template is not approved', function () {
    $off = alertTenancy(mode: 'sms');
    app(SmsNotificationService::class)->invoiceCreated($off['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms')->and($this->sentSms)->toHaveCount(1);

    $notAgreed = alertTenancy(agreed: false);
    app(SmsNotificationService::class)->invoiceCreated($notAgreed['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms')->and($this->sentSms)->toHaveCount(2);

    $pending = alertTenancy();
    Templates::setStatus('primora_invoice_issued', 'PENDING');
    app(SmsNotificationService::class)->invoiceCreated($pending['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms')->and($this->sentSms)->toHaveCount(3)
        ->and(templateRequests())->toBe([])
        ->and($pending['landlord']->fresh()->whatsapp_balance)->toBe(5);
});

test('with no WhatsApp credit the alert goes by SMS, leaving no WhatsApp row behind', function () {
    $t = alertTenancy(waCredit: 0);

    app(SmsNotificationService::class)->invoiceUpcoming($t['invoice']);

    expect(alertLogs())->toHaveCount(1)->and(alertLogs()->sole()->channel)->toBe('sms')
        ->and($this->sentSms)->toHaveCount(1)->and(templateRequests())->toBe([]);
});

test('if WhatsApp refuses the alert it is sent by SMS instead and the WhatsApp credit returned', function () {
    $this->metaAccepts = false;
    $t = alertTenancy();

    app(SmsNotificationService::class)->invoiceOverdue($t['invoice']);

    [$wa, $sms] = alertLogs()->all();
    expect($wa->channel)->toBe('whatsapp')->and($wa->status)->toBe('failed')->and($wa->error)->toContain('not in allowed list')
        ->and($wa->resent_log_id)->toBe($sms->id)->and($wa->canResend())->toBeFalse()
        ->and($sms->channel)->toBe('sms')->and($sms->status)->toBe('sent')->and($sms->notification_type)->toBe('overdue_reminder')
        ->and($t['landlord']->fresh()->whatsapp_balance)->toBe(5)
        ->and($this->sentSms)->toHaveCount(1)->and($this->sentSms[0][1])->toContain('is now overdue');

    // Already sent today (by SMS), so a second run of the reminder sends nothing.
    app(SmsNotificationService::class)->invoiceOverdue($t['invoice']);
    expect(alertLogs())->toHaveCount(2)->and($this->sentSms)->toHaveCount(1);
});

test('Meta reporting an alert undelivered sends it by SMS; a number not on WhatsApp gets SMS for a while', function () {
    $t = alertTenancy();
    app(SmsNotificationService::class)->paymentReceived(\App\Models\Payment::create([
        'payable_type' => Tenant::class, 'payable_id' => $t['tenant']->id, 'amount' => 200000, 'payment_date' => today(),
        'payment_method' => 'cash', 'reference' => 'R-1',
    ]));
    $wa = alertLogs()->sole();
    expect($wa->status)->toBe('sent')->and(templateRequests()[0]['template']['name'])->toBe('primora_payment_received');

    metaStatus($this, $wa->wamid, 'failed', [['code' => 131026, 'title' => 'Message undeliverable']]);
    metaStatus($this, $wa->wamid, 'failed', [['code' => 131026, 'title' => 'Message undeliverable']]); // Meta retries: handled once

    [$wa, $sms] = alertLogs()->all();
    expect($wa->status)->toBe('failed')->and($wa->error)->toContain('Message undeliverable')->and($wa->resent_log_id)->toBe($sms->id)
        ->and($sms->channel)->toBe('sms')->and($this->sentSms)->toHaveCount(1)
        ->and($t['landlord']->fresh()->whatsapp_balance)->toBe(5)
        ->and($t['tenant']->fresh()->whatsapp_failed_at)->not->toBeNull()
        ->and($t['tenant']->fresh()->whatsAppAlertsOn())->toBeFalse();

    CurrentLandlord::set($t['landlord']->id);
    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms')->and(templateRequests())->toHaveCount(1);
});

test('"Both": the tenant gets WhatsApp and SMS, using both credits; a WhatsApp failure sends no second SMS', function () {
    $t = alertTenancy(mode: 'both');

    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);

    [$wa, $sms] = alertLogs()->all();
    expect($wa->channel)->toBe('whatsapp')->and($wa->status)->toBe('sent')
        ->and($sms->channel)->toBe('sms')->and($sms->status)->toBe('sent')
        ->and($this->sentSms)->toHaveCount(1)
        ->and($t['landlord']->fresh()->whatsapp_balance)->toBe(4)
        ->and($t['landlord']->fresh()->sms_balance)->toBeLessThan(20);

    metaStatus($this, $wa->wamid, 'failed', [['code' => 131049, 'title' => 'Not delivered']]);
    expect(alertLogs())->toHaveCount(2)->and($this->sentSms)->toHaveCount(1)
        ->and($wa->fresh()->status)->toBe('failed')->and($t['landlord']->fresh()->whatsapp_balance)->toBe(5);

    // A tenant who can't get WhatsApp gets the SMS only.
    $t['tenant']->forceFill(['whatsapp_opted_out_at' => now()])->save();
    app(SmsNotificationService::class)->invoiceUpcoming($t['invoice']);
    expect(alertLogs())->toHaveCount(3)->and(alertLogs()->last()->channel)->toBe('sms')->and(templateRequests())->toHaveCount(1);
});

test('"Only WhatsApp": no SMS, even when WhatsApp fails; tenants who can\'t get WhatsApp still get SMS', function () {
    $this->metaAccepts = false;
    $t = alertTenancy(mode: 'whatsapp');

    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);

    $wa = alertLogs()->sole();
    expect($wa->channel)->toBe('whatsapp')->and($wa->status)->toBe('failed')->and($wa->resent_log_id)->toBeNull()
        ->and($this->sentSms)->toBe([])->and($t['landlord']->fresh()->whatsapp_balance)->toBe(5);

    $notAgreed = alertTenancy(mode: 'whatsapp', agreed: false);
    app(SmsNotificationService::class)->invoiceCreated($notAgreed['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms')->and($this->sentSms)->toHaveCount(1);
});

test('delivered and read receipts show in the log and never go backwards', function () {
    $t = alertTenancy();
    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);
    $wa = alertLogs()->sole();

    metaStatus($this, $wa->wamid, 'delivered');
    expect($wa->fresh()->status)->toBe('delivered');
    metaStatus($this, $wa->wamid, 'read');
    metaStatus($this, $wa->wamid, 'delivered'); // arrives late
    expect($wa->fresh()->status)->toBe('read')->and($this->sentSms)->toBe([]);

    CurrentLandlord::set($t['landlord']->id);
    $this->actingAs(User::factory()->admin()->create(['landlord_id' => $t['landlord']->id]))
        ->get(route('sms-logs.index'))->assertOk()->assertSee('WhatsApp · read');
});

test('issue updates go by WhatsApp only: never by SMS', function () {
    $t = alertTenancy();
    $issue = Issue::create(['number' => 'ISS-1', 'tenant_id' => $t['tenant']->id, 'lease_id' => $t['lease']->id, 'unit_id' => $t['unit']->id,
        'category' => 'plumbing', 'title' => 'Kitchen sink leaking', 'description' => 'Drips all night', 'status' => 'open']);
    $owner = User::factory()->admin()->create(['landlord_id' => $t['landlord']->id]);

    (new RespondToIssue)($issue, 'in_progress', 'Plumber comes Friday', $owner);

    $log = alertLogs()->sole();
    $body = collect(collect(templateRequests()[0]['template']['components'])->firstWhere('type', 'body')['parameters'])->pluck('text')->all();
    expect($log->channel)->toBe('whatsapp')->and($log->notification_type)->toBe('issue_update')
        ->and($body)->toBe(['Grace Namata', 'Kitchen sink leaking', Issue::STATUSES['in_progress'][0], 'Plumber comes Friday']);

    // Undelivered: credit back, and no SMS (issue updates never were SMS).
    metaStatus($this, $log->wamid, 'failed', [['code' => 131047, 'title' => 'Re-engagement message']]);
    expect(alertLogs())->toHaveCount(1)->and($this->sentSms)->toBe([])->and($t['landlord']->fresh()->whatsapp_balance)->toBe(5);

    // Not on WhatsApp alerts: nothing at all.
    $t['tenant']->forceFill(['whatsapp_opted_out_at' => now()])->save();
    (new RespondToIssue)($issue->fresh(), 'resolved', null, $owner);
    expect(alertLogs())->toHaveCount(1)->and($this->sentSms)->toBe([]);
});

// --- Tenants: consent and stopping -------------------------------------------------------

function tenantTaps($test, string $payload)
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'contacts' => [['wa_id' => '256772123456', 'profile' => ['name' => 'Grace']]],
        'messages' => [['from' => '256772123456', 'id' => 'wamid.tap.'.uniqid(), 'type' => 'button', 'button' => ['text' => 'x', 'payload' => $payload]]],
    ]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();
}

test('"Stop WhatsApp alerts" switches the tenant to SMS; ALERTS ON switches back', function () {
    $t = alertTenancy();
    CurrentLandlord::clear();

    tenantTaps($this, Templates::STOP);
    expect($t['tenant']->fresh()->whatsapp_opted_out_at)->not->toBeNull()->and($t['tenant']->fresh()->whatsAppAlertsOn())->toBeFalse();

    CurrentLandlord::set($t['landlord']->id);
    app(SmsNotificationService::class)->invoiceCreated($t['invoice']);
    expect(alertLogs()->last()->channel)->toBe('sms');

    // The landlord can't tick them back in.
    $t['tenant']->fresh()->setWhatsAppConsent(true);
    expect($t['tenant']->fresh()->whatsAppAlertsOn())->toBeFalse();

    CurrentLandlord::clear();
    tenantTaps($this, 'alerts:on');
    expect($t['tenant']->fresh()->whatsAppAlertsOn())->toBeTrue();
});

test('a tenant who messages the bot from their own number has agreed to WhatsApp alerts', function () {
    $t = alertTenancy(agreed: false);
    CurrentLandlord::clear();
    expect($t['tenant']->whatsapp_opted_in_at)->toBeNull();

    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'messages' => [['from' => '256772123456', 'id' => 'wamid.hi.1', 'type' => 'text', 'text' => ['body' => 'Hi']]],
    ]]]]]]);
    $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();

    expect($t['tenant']->fresh()->whatsAppAlertsOn())->toBeTrue();
});

test('the landlord records consent on the tenant form', function () {
    $t = alertTenancy(agreed: false);
    $admin = User::factory()->admin()->create(['landlord_id' => $t['landlord']->id]);
    $tenant = $t['tenant'];
    $form = ['type' => 'individual', 'name' => $tenant->name, 'email' => $tenant->email, 'primary_phone' => '0772123456',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'Kin', 'emergency_contact_relationship' => 'family',
        'emergency_contact_phone' => '0700000000'];

    $this->actingAs($admin)->put(route('tenants.update', $tenant), $form + ['whatsapp_alerts' => '1'])->assertSessionHasNoErrors();
    expect($tenant->fresh()->whatsapp_opted_in_at)->not->toBeNull();

    $this->actingAs($admin)->put(route('tenants.update', $tenant), $form + ['whatsapp_alerts' => '0'])->assertSessionHasNoErrors();
    expect($tenant->fresh()->whatsapp_opted_in_at)->toBeNull();
});

// --- Landlord page, credit, staff ---------------------------------------------------------

test('switching WhatsApp alerts on gives the free starter alerts once', function () {
    Settings::set('whatsapp.starter_credits', '10');
    $t = alertTenancy(mode: 'sms', waCredit: 0);
    $admin = User::factory()->admin()->create(['landlord_id' => $t['landlord']->id]);

    $this->actingAs($admin)->put(route('whatsapp-alerts.toggle'), ['alert_channels' => 'both'])->assertSessionHas('success');
    $this->actingAs($admin)->put(route('whatsapp-alerts.toggle'), ['alert_channels' => 'sms']);
    $this->actingAs($admin)->put(route('whatsapp-alerts.toggle'), ['alert_channels' => 'whatsapp']);
    $this->actingAs($admin)->put(route('whatsapp-alerts.toggle'), ['alert_channels' => 'email'])->assertSessionHasErrors('alert_channels');

    expect($t['landlord']->fresh()->alert_channels)->toBe('whatsapp')->and($t['landlord']->fresh()->whatsapp_balance)->toBe(10)
        ->and(WhatsAppCreditTransaction::where('type', 'starter')->count())->toBe(1);
    $this->actingAs($admin)->get(route('whatsapp-alerts.show'))->assertOk()->assertSee('How tenants get alerts')->assertSee('Only WhatsApp')->assertSee('Tenants who agreed');
});

test('buying a WhatsApp bundle adds the alerts once paid and books it as WhatsApp sales', function () {
    $bundle = WhatsAppBundle::create(['name' => 'Standard', 'quantity' => 200, 'price' => 20000, 'is_active' => true]);
    $t = alertTenancy(waCredit: 0);
    $admin = User::factory()->admin()->create(['landlord_id' => $t['landlord']->id]);

    $this->actingAs($admin)->post(route('whatsapp-alerts.buy'), ['bundle_id' => $bundle->id, 'phone' => '0772 123 456'])
        ->assertRedirect(route('whatsapp-alerts.show'));

    $tx = PaymentTransaction::latest('id')->sole();
    expect($tx->purpose)->toBe('whatsapp')->and($tx->reference)->toStartWith('WA-')->and($tx->narrative)->toContain('PRIMORA:WA');

    PaymentTransaction::whereKey($tx->id)->update(['last_checked_at' => now()->subMinute()]);
    $this->actingAs($admin)->getJson(route('whatsapp-alerts.payment-status', $tx->reference))
        ->assertOk()->assertJson(['status' => 'succeeded', 'sms_balance' => 200]);

    expect($t['landlord']->fresh()->whatsapp_balance)->toBe(200)->and($t['landlord']->fresh()->sms_balance)->toBe(20)
        ->and(app(Ledger::class)->balance('whatsapp_income'))->toBe(20000.0);
});

test('staff manage WhatsApp bundles, settings and balances, and see template status', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.view', 'sms.manage', 'sms.credits'])->create();
    $t = alertTenancy(waCredit: 0);
    CurrentLandlord::clear();

    $this->actingAs($staff, 'staff')->post(route('staff.sms.whatsapp.bundles.store'), ['name' => 'Starter', 'quantity' => 100, 'price' => 12000, 'is_active' => 1])
        ->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->put(route('staff.sms.whatsapp.settings'), ['starter_credits' => 25, 'low_balance_threshold' => 5]);
    $this->actingAs($staff, 'staff')->post(route('staff.sms.whatsapp.adjust'), ['landlord_id' => $t['landlord']->id, 'quantity' => 30, 'note' => 'Goodwill'])
        ->assertSessionHas('success');

    expect(WhatsAppBundle::sole()->name)->toBe('Starter')->and(Settings::int('whatsapp.starter_credits'))->toBe(25)
        ->and($t['landlord']->fresh()->whatsapp_balance)->toBe(30)
        ->and(\App\Models\SmsBundle::count())->toBe(0);

    $this->actingAs($staff, 'staff')->get(route('staff.sms.whatsapp'))->assertOk()
        ->assertSee('primora_invoice_issued')->assertSee('Approved')->assertSee('Kololo Homes');
});

test('Meta telling us a template was approved or rejected is saved', function () {
    Templates::setStatus('primora_lease_expiring', 'PENDING');
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'message_template_status_update', 'value' => [
        'event' => 'REJECTED', 'message_template_name' => 'primora_lease_expiring', 'message_template_language' => 'en', 'reason' => 'INVALID_FORMAT',
    ]]]]]]);
    $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();

    expect(Templates::statuses()['primora_lease_expiring'])->toBe('REJECTED')->and(Templates::approved('primora_lease_expiring'))->toBeFalse();
});

test('templates are submitted to Meta with examples, buttons and a sample PDF for the ones with attachments', function () {
    Settings::set('whatsapp.templates', '[]');
    Http::swap(new \Illuminate\Http\Client\Factory); // drop the catch-all fake from beforeEach
    Http::fake([
        'graph.facebook.com/*/message_templates?*' => Http::response(['data' => [['name' => 'primora_issue_update', 'status' => 'APPROVED', 'language' => 'en']]]),
        'graph.facebook.com/*/app' => Http::response(['id' => '777']),
        'graph.facebook.com/*/777/uploads*' => Http::response(['id' => 'upload:abc']),
        'graph.facebook.com/*/upload:abc' => Http::response(['h' => 'handle-1']),
        'graph.facebook.com/*/message_templates' => Http::response(['id' => 't1', 'status' => 'PENDING']),
    ]);

    $this->artisan('whatsapp:templates', ['--submit' => true])->assertSuccessful();

    $created = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/message_templates'));
    expect($created)->toHaveCount(count(Templates::all()) - 1);
    $invoice = $created->first(fn ($r) => $r['name'] === 'primora_invoice_issued');
    expect($invoice['category'])->toBe('UTILITY')->and($invoice['components'][0])->toMatchArray(['type' => 'HEADER', 'format' => 'DOCUMENT'])
        ->and($invoice['components'][0]['example']['header_handle'])->toBe(['handle-1'])
        ->and(collect($invoice['components'])->firstWhere('type', 'BUTTONS')['buttons'][1]['text'])->toBe('Stop WhatsApp alerts');
    expect(Templates::statuses())->toMatchArray(['primora_issue_update' => 'APPROVED', 'primora_invoice_issued' => 'PENDING']);
});

test('no template body starts or ends with a variable, and every example fills every variable', function () {
    foreach (Templates::all() as $name => $t) {
        preg_match_all('/\{\{(\d+)\}\}/', $t['body'], $m);
        expect(preg_match('/^\{\{|\}\}[.!]?$/', trim($t['body'])))->toBe(0, $name)
            ->and(max(array_map('intval', $m[1])))->toBe(count($t['example']), $name)
            ->and(end($t['buttons']))->toBe('Stop WhatsApp alerts');
    }
});
