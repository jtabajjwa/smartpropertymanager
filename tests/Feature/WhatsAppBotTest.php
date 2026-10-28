<?php

use App\Mail\IssueReportedMail;
use App\Models\CollectionFeeTier;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSession;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Tenant WhatsApp bot: Meta's webhook (verified, signed), who's messaging,
 * and the menu: balance, invoices, paying, issues.
 */

beforeEach(function () {
    config([
        'services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '1234567890',
        'services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me',
        'payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0,
    ]);
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'media-1']),
        'graph.facebook.com/*/messages' => Http::sequence()->whenEmpty(Http::response(['messages' => [['id' => 'wamid.out']]])),
        '*' => Http::response([]),
    ]);
    $this->sentSms = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sentSms[] = [$phone, $message];

            return true;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

/** Grace (0772 123 456) renting unit A4 from Kololo Homes, with one unpaid 500,000 invoice. */
function waTenancy(bool $collect = true): array
{
    $landlord = Landlord::factory()->create(['name' => 'Kololo Homes', 'collect_payments' => $collect, 'contact_email' => 'owner@kololo.test']);
    CurrentLandlord::set($landlord->id);
    $estate = Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'A4', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'grace@example.test', 'primary_phone' => '0772 123 456',
        'id_type' => 'national_id', 'id_number' => 'NIN-1', 'emergency_contact_name' => 'Kin',
        'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(), 'number' => 'LSE-00001',
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-00001', 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'October rent', 'invoice_date' => '2026-10-01', 'amount' => 500000, 'due_date' => today()->addDays(5)->toDateString(), 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'unit', 'tenant', 'invoice');
}

/** Posts a message to the webhook as Meta would (signed). $reply: a button / list choice id. */
function waSend($test, string $from, ?string $text = null, ?string $reply = null, array $extra = [])
{
    static $n = 0;
    $message = ['from' => $from, 'id' => 'wamid.in.'.(++$n), 'timestamp' => (string) time()] + match (true) {
        $reply !== null => ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $reply, 'title' => $reply]]],
        $extra !== [] => $extra,
        default => ['type' => 'text', 'text' => ['body' => $text]],
    };
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Grace']]], 'messages' => [$message],
    ]]]]]]);

    return $test->call('POST', '/api/whatsapp/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);
}

/** The text of the last message the bot sent to $to. */
function waLast(string $to): string
{
    return (string) WhatsAppMessage::where('wa_id', $to)->where('direction', 'out')->latest('id')->value('body');
}

function waLastPayload(string $to): array
{
    return WhatsAppMessage::where('wa_id', $to)->where('direction', 'out')->latest('id')->value('payload') ?? [];
}

// --- Webhook --------------------------------------------------------------------------

test('the webhook answers Meta\'s verification only with the right token', function () {
    $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertSee('12345');
    $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
});

test('unsigned or wrongly signed messages are refused, and retries are handled once', function () {
    waTenancy();
    $this->postJson('/api/whatsapp/webhook', ['entry' => []])->assertForbidden();

    $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '256772123456', 'id' => 'wamid.dup', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')];
    $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body)->assertOk();

    expect(WhatsAppMessage::where('direction', 'in')->count())->toBe(1)
        ->and(WhatsAppMessage::where('direction', 'out')->count())->toBe(1);
});

// --- Who's messaging --------------------------------------------------------------------

test('a tenant messaging from their own number gets the menu straight away', function () {
    waTenancy();

    waSend($this, '256772123456', 'Hi')->assertOk();

    $payload = waLastPayload('256772123456');
    $rows = collect($payload['interactive']['action']['sections'][0]['rows'])->pluck('title')->all();
    expect(waLast('256772123456'))->toBe('Hi Grace, what would you like to do?')
        ->and($payload['interactive']['header']['text'])->toBe('Kololo Homes')
        ->and($rows)->toBe(['My balance', 'Invoices', 'Pay rent', 'My payments', 'Report an issue', 'My issues', 'My tenancy']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/1234567890/messages') && $r->hasHeader('Authorization', 'Bearer test-token') && ($r['status'] ?? null) === 'read');
});

test('an unknown number can sign in with the unit ID and a code sent to the phone on record', function () {
    ['unit' => $unit] = waTenancy();
    $stranger = '256701555000';

    waSend($this, $stranger, 'Hello');
    expect(waLast($stranger))->toContain("isn't on any tenancy")->toContain('unit ID');

    waSend($this, $stranger, 'A4');
    expect(waLast($stranger))->toContain("couldn't find that");

    waSend($this, $stranger, $unit->code);
    expect(waLast($stranger))->toContain('We sent a 6-digit code by SMS to 07•• ••• 456')
        ->and(end($this->sentSms)[0])->toBe('256772123456');
    preg_match('/^(\d{6})/', end($this->sentSms)[1], $m);

    waSend($this, $stranger, '000000');
    expect(waLast($stranger))->toContain("That code isn't right");

    waSend($this, $stranger, $m[1]);
    expect(waLast($stranger))->toBe('Hi Grace, what would you like to do?');
    expect(collect(waLastPayload($stranger)['interactive']['action']['sections'][0]['rows'])->pluck('id'))->toContain('logout');

    waSend($this, $stranger, null, 'logout');
    expect(waLast($stranger))->toContain('You have signed out');
    waSend($this, $stranger, 'Hi');
    expect(waLast($stranger))->toContain("isn't on any tenancy");
});

test('a number on two tenancies chooses which account to use', function () {
    ['tenant' => $a] = waTenancy();
    $other = Landlord::factory()->create(['name' => 'Ntinda Flats']);
    CurrentLandlord::set($other->id);
    $estate = Estate::create(['name' => 'Ntinda Courts', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'B1', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $b = Tenant::create([
        'type' => 'individual', 'name' => 'Grace N.', 'email' => 'grace2@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'NIN-2', 'emergency_contact_name' => 'Kin', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    Lease::create(['tenant_id' => $b->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 300000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->addMonth()->toDateString(), 'number' => 'LSE-00009']);
    CurrentLandlord::clear();

    waSend($this, '256772123456', 'hi');
    expect(waLast('256772123456'))->toContain('more than one tenancy');

    waSend($this, '256772123456', null, 'acct:'.$b->id);
    expect(waLastPayload('256772123456')['interactive']['header']['text'])->toBe('Ntinda Flats')
        ->and(WhatsAppSession::first()->tenant_id)->toBe($b->id);
});

// --- Balance, invoices, paying, issues ------------------------------------------------

test('a tenant sees their balance and gets an invoice as a PDF', function () {
    ['invoice' => $invoice] = waTenancy();
    $me = '256772123456';

    waSend($this, $me, null, 'balance');
    expect(waLast($me))->toContain('You owe: UGX 500,000')->toContain('INV-00001');

    waSend($this, $me, 'invoices');
    expect(collect(waLastPayload($me)['interactive']['action']['sections'][0]['rows'])->pluck('id')->all())->toBe(['inv:'.$invoice->id]);

    waSend($this, $me, null, 'inv:'.$invoice->id);
    $document = WhatsAppMessage::where('wa_id', $me)->where('type', 'document')->sole();
    expect($document->body)->toStartWith('invoice-INV-00001.pdf')->and($document->payload['document']['id'])->toBe('media-1');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/1234567890/media'));
    expect(waLast($me))->toBe('Still due: UGX 500,000.');
});

test('a tenant pays an invoice from WhatsApp and gets the confirmation there', function () {
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1000]);
    ['invoice' => $invoice, 'landlord' => $landlord] = waTenancy();
    $landlord->update(['pass_collection_fee' => true]);
    $me = '256772123456';

    waSend($this, $me, null, 'pay');
    waSend($this, $me, null, 'pay:'.$invoice->id);
    expect(waLast($me))->toContain('UGX 500,000 is due on invoice INV-00001');

    waSend($this, $me, null, 'amt:other');
    waSend($this, $me, '600,000');
    expect(waLast($me))->toContain('more than the UGX 500,000 due');
    waSend($this, $me, 'UGX 200,000');
    waSend($this, $me, null, 'phone:other');
    waSend($this, $me, '0701 999 888');
    expect(waLast($me))->toContain('Pay UGX 200,000 to Kololo Homes for invoice INV-00001')
        ->toContain('Fee: UGX 1,000 (added)')->toContain('You will be charged: UGX 201,000')->toContain('From: 0701 999 888');

    waSend($this, $me, null, 'pay:confirm');
    expect(waLast($me))->toContain('Payment request sent to');

    $tx = PaymentTransaction::sole();
    expect($tx->msisdn)->toBe('256701999888')->and((float) $tx->amount)->toBe(201000.0)->and($tx->context['whatsapp_to'])->toBe($me);

    app(PaymentService::class)->refresh($tx);
    $payment = Payment::withoutGlobalScopes()->sole();
    expect(waLast($me))->toBe("Payment received: UGX 200,000 for invoice INV-00001. Receipt {$payment->number}. Thank you!");
});

test('paying is only offered where Primora collects for the landlord', function () {
    waTenancy(collect: false);
    $me = '256772123456';

    waSend($this, $me, 'hi');
    expect(collect(waLastPayload($me)['interactive']['action']['sections'][0]['rows'])->pluck('id'))->not->toContain('pay');

    waSend($this, $me, 'pay');
    expect(waLast($me))->toContain("doesn't take mobile money payments");
});

test('a tenant reports an issue, and the landlord is emailed', function () {
    Mail::fake();
    ['tenant' => $tenant] = waTenancy();
    $me = '256772123456';

    waSend($this, $me, null, 'issue_new');
    waSend($this, $me, null, 'cat:repair');
    waSend($this, $me, 'The kitchen tap is leaking');
    waSend($this, $me, null, 'issue:no_photo');

    $issue = Issue::withoutGlobalScopes()->sole();
    expect($issue->tenant_id)->toBe($tenant->id)->and($issue->category)->toBe('repair')->and($issue->title)->toBe('The kitchen tap is leaking')
        ->and($issue->unit_id)->not->toBeNull()
        ->and(waLast($me))->toContain("Issue {$issue->number}: The kitchen tap is leaking");
    Mail::assertSent(IssueReportedMail::class);

    waSend($this, $me, null, 'iss:'.$issue->id);
    expect(waLast($me))->toContain('Status: Open');
});

test('without Meta credentials replies are only logged', function () {
    config(['services.whatsapp.token' => null]);
    waTenancy();

    waSend($this, '256772123456', 'hi');

    expect(WhatsAppMessage::where('direction', 'out')->value('status'))->toBe('logged');
    Http::assertNothingSent();
});
