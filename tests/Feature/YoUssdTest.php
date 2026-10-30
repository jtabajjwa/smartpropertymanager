<?php

use App\Models\CollectionFeeTier;
use App\Models\Estate;
use App\Models\Invoice;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UssdPaymentIntent;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use App\Ussd\UssdPayments;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Yo! Custom USSD: Yo! hosts the menu and calls Primora at three steps
 * (unit ID typed, units for a phone, unit + amount chosen), then sends the
 * mobile money prompt itself with the reference we handed out. Yo! is
 * simulated: call-outs are signed with a test key, and its payments API
 * (status checks) is faked.
 */

beforeEach(function () {
    $fixtures = base_path('tests/Fixtures/payments');
    $this->ussdKey = openssl_pkey_get_private(file_get_contents("{$fixtures}/yo-test-private.pem"));
    config([
        'services.yo_ussd.mode' => 'sandbox',
        'services.yo_ussd.public_keys.sandbox' => "{$fixtures}/yo-test-public.pem",
        'payments.gateways.yo.api_username' => 'test-user',
        'payments.gateways.yo.api_password' => 'test-pass',
        'payments.gateways.yo.mode' => 'sandbox',
        'payments.gateways.yo.certificates.sandbox' => "{$fixtures}/yo-test-public.pem",
    ]);

    // What Yo!'s status check reports for a reference; tests change it.
    $this->yoCheck = ['status' => 'SUCCEEDED', 'amount' => '200000'];
    Http::fake(function (HttpRequest $request) {
        if (!str_contains($request->body(), '<Method>actransactioncheckstatus</Method>')) {
            return Http::response('', 500);
        }
        $fields = $this->yoCheck['status'] === 'NOTFOUND'
            ? '<Status>ERROR</Status><StatusCode>-30</StatusCode><StatusMessage>Not found</StatusMessage>'
            : "<Status>OK</Status><StatusCode>0</StatusCode><TransactionStatus>{$this->yoCheck['status']}</TransactionStatus><TransactionReference>YO-USSD-1</TransactionReference><MNOTransactionReferenceId>MTN-991</MNOTransactionReferenceId><Amount>{$this->yoCheck['amount']}</Amount>";

        return Http::response('<?xml version="1.0" encoding="UTF-8"?><AutoCreate><Response>'.$fields.'</Response></AutoCreate>');
    });

    $this->mock(SmsService::class, fn ($mock) => $mock->shouldReceive('send')->andReturn(true));
});
afterEach(fn () => CurrentLandlord::clear());

/** A landlord Primora collects for, with Grace in unit A4 (500,000 unpaid). */
function ussdTenancy(string $phone = '+256772123456', bool $collect = true, bool $passFee = false, string $tenantName = 'Grace Namata'): array
{
    $landlord = Landlord::factory()->create(['collect_payments' => $collect, 'pass_collection_fee' => $passFee]);
    CurrentLandlord::set($landlord->id);

    $estate = Estate::create(['name' => 'Acacia Court', 'district' => 'Kampala']);
    $unit = Unit::create(['name' => 'A4', 'estate_id' => $estate->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => $tenantName, 'email' => fake()->unique()->safeEmail(), 'primary_phone' => $phone,
        'id_type' => 'national_id', 'id_number' => 'NIN-'.fake()->unique()->randomNumber(8),
        'emergency_contact_name' => 'Emergency', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-01-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 0, 'required_upfront_periods' => 1, 'next_invoice_date' => today()->toDateString(),
        'number' => 'LSE-'.fake()->unique()->randomNumber(6),
    ]);
    $invoice = Invoice::create([
        'number' => 'INV-'.fake()->unique()->randomNumber(6), 'lease_id' => $lease->id, 'invoiceable_type' => Lease::class, 'invoiceable_id' => $lease->id,
        'type' => 'rent', 'title' => 'October rent', 'invoice_date' => '2026-10-01', 'amount' => 500000, 'due_date' => '2026-10-05', 'status' => 'unpaid',
    ]);
    CurrentLandlord::clear();

    return compact('landlord', 'tenant', 'lease', 'invoice', 'unit');
}

/** A call-out from Yo!'s USSD app, signed like Yo! signs them. */
function ussdCall($test, string $step, array $vars = [], string $caller = '256772123456', ?string $signature = null)
{
    $datetime = '20261030101500';
    openssl_sign(sha1($datetime.$caller), $sig, $test->ussdKey, OPENSSL_ALGO_SHA1);

    return $test->postJson(route('api.ussd.yo', $step), ['datetime' => $datetime, 'anumbermsisdn' => $caller, 'signature' => $signature ?? base64_encode($sig)] + $vars)
        ->assertOk();
}

/** Yo!'s payment notification (IPN) for a USSD payment, signed with the test key. */
function ussdIpn($test, string $reference, string $amount = '200000', string $msisdn = '256772123456')
{
    $p = ['date_time' => '2026-10-30 10:20:00', 'amount' => $amount, 'narrative' => '{"propty_unit_number":"1000017","tenant_name":"Grace Namata"}',
        'network_ref' => 'MTN-991', 'external_ref' => $reference, 'msisdn' => $msisdn];
    openssl_sign($p['date_time'].$p['amount'].$p['narrative'].$p['network_ref'].$p['external_ref'].$p['msisdn'], $sig, $test->ussdKey, OPENSSL_ALGO_SHA1);

    return $test->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), $p + ['signature' => base64_encode($sig)])->assertOk();
}

// --- Call-outs -----------------------------------------------------------

test('call-outs without a valid Yo! signature are refused', function () {
    $t = ussdTenancy();

    ussdCall($this, 'unit', ['property_unit_number' => $t['unit']->code], signature: base64_encode('forged'))
        ->assertJson(['validated' => false]);
    expect(UssdPaymentIntent::count())->toBe(0);
});

test('a unit ID shows the tenancy and hands out a payment reference', function () {
    $t = ussdTenancy();

    $json = ussdCall($this, 'unit', ['property_unit_number ' => $t['unit']->code])->json();   // stray space in the key, as configured

    $intent = UssdPaymentIntent::sole();
    expect($json['validated'])->toBeTrue()
        ->and($json['message'])->toBe("Primora PropTech:\nEstate: Acacia Court\nTenant: Grace Namata\nUnit No: A4 ({$t['unit']->code})\nBal: UGX 500,000")
        ->and($json['ussd_processor_params'])->toMatchArray([
            'tenant_name' => 'Grace Namata', 'estate_name' => 'Acacia Court', 'property_unit_number' => $t['unit']->code,
            'balance_formatted' => 'UGX 500,000', 'payment_external_reference' => $intent->reference,
        ])
        ->and($json['success_ipn_url'])->toBe(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']))
        ->and($json['failure_ipn_url'])->toBe(route('payments.callback', ['gateway' => 'yo', 'type' => 'failure']))
        ->and($intent->reference)->toStartWith('RNT-')
        ->and($intent->lease_id)->toBe($t['lease']->id)
        ->and($intent->amount)->toBeNull()
        ->and(PaymentTransaction::count())->toBe(0);   // nothing is a payment until money moves
});

test('a wrong unit ID, an empty unit or a landlord Primora does not collect for ends the session', function () {
    $t = ussdTenancy();
    $notCollecting = ussdTenancy('+256701111111', collect: false);

    expect(ussdCall($this, 'unit', ['property_unit_number' => '1234567'])->json())->toMatchArray(['validated' => false])
        ->and(ussdCall($this, 'unit', ['property_unit_number' => $notCollecting['unit']->code])->json('message'))->toContain("doesn't take payments through Primora");

    CurrentLandlord::runAs($t['landlord']->id, fn () => $t['lease']->update(['status' => 'terminated']));
    expect(ussdCall($this, 'unit', ['property_unit_number' => $t['unit']->code])->json('validated'))->toBeFalse();
});

test('the caller sees the units on their own phone, or on another tenant\'s', function () {
    ussdTenancy();

    $own = ussdCall($this, 'units')->json();
    expect($own['validated'])->toBeTrue()
        ->and($own['ussd_processor_params']['property_unit_list_text'])->toBe("Primora PropTech:\n1. Acacia Court A4 (Grace, Bal: UGX 500,000)")
        ->and($own['ussd_processor_params']['user_phonenumber'])->toBe('256772123456');

    // A relative paying from another phone, typing the tenant's number as they know it.
    $other = ussdCall($this, 'units', ['other_tenant_phonenumber' => '0772 123 456'], caller: '256701999999')->json();
    expect($other['ussd_processor_params']['user_phonenumber'])->toBe('256772123456')
        ->and($other['message'])->toContain('that phone number');

    expect(ussdCall($this, 'units', caller: '256701999999')->json('validated'))->toBeFalse()
        ->and(ussdCall($this, 'units', ['other_tenant_phonenumber' => '12'])->json('validated'))->toBeFalse();
});

test('choosing a listed unit and an amount hands out a reference for that amount', function () {
    $t = ussdTenancy();

    $json = ussdCall($this, 'confirm', ['selected_property_number' => '1', 'user_phonenumber' => '256772123456', 'amount ' => '200,000'], caller: '256701999999')->json();

    $intent = UssdPaymentIntent::sole();
    expect($json['validated'])->toBeTrue()
        ->and($json['ussd_processor_params'])->toMatchArray([
            'tenant_name' => 'Grace Namata', 'estate_name' => 'Acacia Court', 'property_unit_number' => $t['unit']->code,
            'amount_formatted' => 'UGX 200,000', 'payment_external_reference' => $intent->reference,
        ])
        ->and((float) $intent->amount)->toBe(200000.0)
        ->and($intent->msisdn)->toBe('256701999999');   // the relative's phone gets the prompt

    expect(ussdCall($this, 'confirm', ['selected_property_number' => '2', 'user_phonenumber' => '256772123456', 'amount' => '200000'])->json('validated'))->toBeFalse()
        ->and(ussdCall($this, 'confirm', ['selected_property_number' => '1', 'user_phonenumber' => '256772123456', 'amount' => '100'])->json('message'))->toContain('smallest amount');
});

// --- Payment -------------------------------------------------------------

test('Yo!\'s notification turns the reference into a recorded rent payment against the oldest invoices', function () {
    $t = ussdTenancy();
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1000]);
    $reference = ussdCall($this, 'unit', ['property_unit_number' => $t['unit']->code])->json('ussd_processor_params.payment_external_reference');

    ussdIpn($this, $reference);
    ussdIpn($this, $reference);   // Yo! retrying changes nothing

    $tx = PaymentTransaction::sole();
    $payment = CurrentLandlord::runAs($t['landlord']->id, fn () => Payment::sole());
    expect($tx->status)->toBe('succeeded')
        ->and($tx->reference)->toBe($reference)
        ->and((float) $tx->amount)->toBe(200000.0)          // the amount was typed after our call-out: taken from Yo!
        ->and((float) $tx->fee_amount)->toBe(1000.0)
        ->and($tx->fee_bearer)->toBe('landlord')
        ->and($tx->context['channel'])->toBe('ussd')
        ->and((float) $payment->amount)->toBe(200000.0)
        ->and($payment->notes)->toContain('(USSD)')
        ->and($payment->status)->toBe('fully_allocated')
        ->and(CurrentLandlord::runAs($t['landlord']->id, fn () => $t['invoice']->fresh()->status))->toBe('partial')
        ->and(UssdPaymentIntent::sole()->status)->toBe('claimed');
});

test('when the landlord passes the fee on, it comes out of what the tenant paid', function () {
    $t = ussdTenancy(passFee: true);
    CollectionFeeTier::create(['min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1000]);
    $reference = ussdCall($this, 'confirm', ['selected_property_number' => '1', 'user_phonenumber' => '256772123456', 'amount' => '200000'])
        ->json('ussd_processor_params.payment_external_reference');

    ussdIpn($this, $reference);

    $tx = PaymentTransaction::sole();
    expect((float) $tx->fee_amount)->toBe(1000.0)
        ->and($tx->fee_bearer)->toBe('tenant')
        ->and((float) $tx->landlord_amount)->toBe(199000.0)
        ->and((float) CurrentLandlord::runAs($t['landlord']->id, fn () => Payment::sole()->amount))->toBe(199000.0);
});

test('a missed notification is picked up by the status checks, and unused references expire', function () {
    $t = ussdTenancy();
    $paid = ussdCall($this, 'unit', ['property_unit_number' => $t['unit']->code])->json('ussd_processor_params.payment_external_reference');
    UssdPaymentIntent::where('reference', $paid)->update(['created_at' => now()->subMinutes(10)]);

    expect(app(UssdPayments::class)->reconcile())->toBe(1)
        ->and(PaymentTransaction::sole()->status)->toBe('succeeded');

    $unused = ussdCall($this, 'unit', ['property_unit_number' => $t['unit']->code])->json('ussd_processor_params.payment_external_reference');
    UssdPaymentIntent::where('reference', $unused)->update(['created_at' => now()->subHours(4)]);
    $this->yoCheck = ['status' => 'NOTFOUND', 'amount' => ''];

    app(UssdPayments::class)->reconcile();
    expect(UssdPaymentIntent::where('reference', $unused)->value('status'))->toBe('expired')
        ->and(PaymentTransaction::count())->toBe(1);
});
