<?php

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Location;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Subscription payments through Yo! Payments (acdepositfunds + IPN, with
 * actransactioncheckstatus polling as backup). Yo! is simulated with Http::fake.
 */

beforeEach(function () {
    config([
        'payments.default' => 'yo',
        'payments.gateways.yo.api_username' => 'test-user',
        'payments.gateways.yo.api_password' => 'test-pass',
        'payments.gateways.yo.mode' => 'sandbox',
    ]);

    // A test-only key pair stands in for Yo!'s, so IPN signatures are checked for real.
    $fixtures = base_path('tests/Fixtures/payments');
    $this->yoPrivateKey = openssl_pkey_get_private(file_get_contents("{$fixtures}/yo-test-private.pem"));
    config(['payments.gateways.yo.certificates.sandbox' => "{$fixtures}/yo-test-public.pem"]);

    // What Yo! answers to actransactioncheckstatus; tests change it.
    $this->yoStatus = 'PENDING';
    $this->yoMno = 'MTN-777';
    $this->yoDeposit = 'PENDING';
    $this->yoRequests = [];

    Http::fake(function (HttpRequest $request) {
        $body = $request->body();
        $this->yoRequests[] = $body;

        if (str_contains($body, '<Method>acdepositfunds</Method>')) {
            if ($this->yoDeposit === 'REJECT') {
                return Http::response(yoXml('ERROR', -2, '', ['StatusMessage' => 'Unsupported MSISDN Network or Currency']));
            }

            return Http::response(yoXml('OK', 1, 'PENDING', ['TransactionReference' => 'YO-TX-1001']));
        }

        if (str_contains($body, '<Method>actransactioncheckstatus</Method>')) {
            return match ($this->yoStatus) {
                'SUCCEEDED' => Http::response(yoXml('OK', 0, 'SUCCEEDED', ['TransactionReference' => 'YO-TX-1001', 'MNOTransactionReferenceId' => $this->yoMno, 'IssuedReceiptNumber' => '55501', 'Amount' => '20000'])),
                'FAILED' => Http::response(yoXml('ERROR', 2, 'FAILED', ['TransactionReference' => 'YO-TX-1001', 'StatusMessage' => 'Transaction failed', 'ErrorMessage' => 'The payer declined.'])),
                'NOTFOUND' => Http::response(yoXml('ERROR', -30, '', ['StatusMessage' => 'The transaction was not found in the system.'])),
                default => Http::response(yoXml('OK', 1, 'PENDING', ['TransactionReference' => 'YO-TX-1001'])),
            };
        }

        return Http::response('', 500);
    });
});

afterEach(fn () => CurrentLandlord::clear());

function yoXml(string $status, int $code, string $txStatus, array $extra = []): string
{
    $fields = "<Status>{$status}</Status><StatusCode>{$code}</StatusCode>".($txStatus ? "<TransactionStatus>{$txStatus}</TransactionStatus>" : '');
    foreach ($extra as $k => $v) {
        $fields .= "<{$k}>".htmlspecialchars($v)."</{$k}>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?><AutoCreate><Response>'.$fields.'</Response></AutoCreate>';
}

/** Landlord admin with $units units, and a tiered plan: 1–6 = 15,000/mo, 7–12 = 20,000/mo. */
function subscriptionSetup(int $units = 8): array
{
    $plan = Plan::factory()->tiered()->create(['name' => 'Premium Plan']);
    PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 1, 'max_units' => 6, 'monthly_price' => 15000, 'quarterly_price' => 45000, 'annual_price' => 180000]);
    PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 7, 'max_units' => 12, 'monthly_price' => 20000, 'quarterly_price' => 60000, 'annual_price' => 240000]);

    $landlord = Landlord::factory()->create(['name' => 'Kololo Homes Ltd', 'subscription_status' => 'trialing']);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    CurrentLandlord::set($landlord->id);
    $location = Location::create(['name' => 'Kololo', 'country' => 'Uganda', 'district' => 'Kampala', 'address' => 'Plot 1']);
    $estate = Estate::create(['name' => 'Acacia', 'location_id' => $location->id]);
    for ($i = 1; $i <= $units; $i++) {
        Unit::create(['name' => "U{$i}", 'estate_id' => $estate->id, 'status' => 'vacant']);
    }
    CurrentLandlord::clear();

    return [$landlord, $admin, $plan];
}

function startPayment($test, User $admin, Plan $plan, string $cycle = 'monthly', string $phone = '0772 123 456'): PaymentTransaction
{
    $test->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => $cycle, 'phone' => $phone])
        ->assertRedirect(route('subscription.show'));

    return PaymentTransaction::latest('id')->firstOrFail();
}

/** An IPN exactly as Yo! sends it, signed with our stand-in key. */
function signedIpn($test, PaymentTransaction $tx, array $overrides = []): array
{
    $p = array_merge([
        'date_time' => '2026-10-27 10:00:00', 'amount' => '20000', 'narrative' => $tx->narrative,
        'network_ref' => 'MTN-777', 'external_ref' => $tx->reference, 'msisdn' => $tx->msisdn, 'payer_names' => 'Jane Owner',
    ], $overrides);

    openssl_sign($p['date_time'].$p['amount'].$p['narrative'].$p['network_ref'].$p['external_ref'].$p['msisdn'], $sig, $test->yoPrivateKey, OPENSSL_ALGO_SHA1);
    $p['signature'] = base64_encode($sig);

    return $p;
}

// --- Starting a payment ---

test('a landlord admin sees plans priced for their size', function () {
    [$landlord, $admin] = subscriptionSetup(8);

    $this->actingAs($admin)->get(route('subscription.show'))
        ->assertOk()
        ->assertSee('Premium Plan')
        ->assertSee('Free trial')
        ->assertViewHas('plans', function ($plans) {
            $default = collect($plans->first()['tiers'])->firstWhere('id', $plans->first()['default_tier']);

            return $default['label'] === '7–12 units' && $default['prices']['monthly'] === 20000.0;
        });
});

test('paying sends a non-blocking acdepositfunds request with a traceable reference and narrative', function () {
    [$landlord, $admin, $plan] = subscriptionSetup(8);

    $tx = startPayment($this, $admin, $plan);

    expect($tx->status)->toBe('pending')
        ->and($tx->purpose)->toBe('subscription')
        ->and((float) $tx->amount)->toBe(20000.0)
        ->and($tx->msisdn)->toBe('256772123456')
        ->and($tx->gateway_reference)->toBe('YO-TX-1001')
        ->and($tx->reference)->toStartWith('SUB-'.now()->format('ymd').'-')      // real date, untouched
        ->and($tx->reference)->toMatch('/^SUB-\d{6}-[A-HJKMNP-Z2-9]{6}$/')       // no 0/O/1/I/L look-alikes
        ->and($tx->narrative)->toStartWith("PRIMORA:SUB L{$landlord->id} {$tx->reference} | Premium Plan, 7–12 units, Monthly | Kololo Homes Ltd")
        ->and($tx->initiated_by_user_id)->toBe($admin->id);

    $xml = $this->yoRequests[0];
    expect($xml)
        ->toContain('<APIUsername>test-user</APIUsername>')
        ->toContain('<Method>acdepositfunds</Method>')
        ->toContain('<NonBlocking>TRUE</NonBlocking>')
        ->toContain('<Amount>20000</Amount>')
        ->toContain('<Account>256772123456</Account>')
        ->toContain("<ExternalReference>{$tx->reference}</ExternalReference>")
        ->toContain('<InstantNotificationUrl>'.route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']).'</InstantNotificationUrl>')
        ->toContain('<FailureNotificationUrl>'.route('payments.callback', ['gateway' => 'yo', 'type' => 'failure']).'</FailureNotificationUrl>');
});

test('the price follows the chosen billing cycle', function () {
    [, $admin, $plan] = subscriptionSetup(3);

    expect((float) startPayment($this, $admin, $plan, 'annual')->amount)->toBe(180000.0);
});

test('a non-Ugandan or malformed number is refused before contacting Yo!', function () {
    [, $admin, $plan] = subscriptionSetup();

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '12345'])
        ->assertSessionHasErrors('phone');

    expect($this->yoRequests)->toBe([])->and(PaymentTransaction::count())->toBe(0);
});

test('a request Yo! rejects outright is recorded as failed and explained', function () {
    [, $admin, $plan] = subscriptionSetup();

    $this->yoDeposit = 'REJECT';

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertSessionHas('error');

    expect(PaymentTransaction::first()->status)->toBe('failed');
});

test('a second prompt is not sent while one is still awaiting approval', function () {
    [, $admin, $plan] = subscriptionSetup();
    startPayment($this, $admin, $plan);

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertSessionHas('error');

    expect(PaymentTransaction::count())->toBe(1);
});

test('only landlord admins can pay', function () {
    [$landlord, , $plan] = subscriptionSetup();
    $user = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'user']);

    $this->actingAs($user)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertForbidden();
});

// --- IPN (primary) ---

test('a signed IPN, confirmed by a status check, activates the subscription once', function () {
    [$landlord, $admin, $plan] = subscriptionSetup(8);
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'SUCCEEDED';

    $ipn = signedIpn($this, $tx);
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), $ipn)->assertOk();

    $tx->refresh();
    $landlord->refresh();
    expect($tx->status)->toBe('succeeded')
        ->and($tx->receipt_number)->toBe('55501')
        ->and($tx->network_reference)->toBe('MTN-777')
        ->and($tx->applied_at)->not->toBeNull()
        ->and($landlord->subscription_status)->toBe('active')
        ->and($landlord->plan_id)->toBe($plan->id)
        ->and($landlord->billing_cycle)->toBe('monthly')
        ->and($landlord->current_period_end->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString());

    // Yo! retries IPNs: a duplicate must not add another month.
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), $ipn)->assertOk();
    expect($landlord->fresh()->current_period_end->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString());
});

test('an IPN is never trusted on its own: if Yo! says pending, nothing is credited', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'PENDING';

    // Forged: wrong signature, claims success.
    $ipn = signedIpn($this, $tx);
    $ipn['signature'] = base64_encode('forged');
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), $ipn)->assertOk();

    expect($tx->fresh()->status)->toBe('pending')
        ->and($landlord->fresh()->subscription_status)->toBe('trialing');
});

test('a failure notification marks the payment failed after confirming with Yo!', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'FAILED';

    $date = '2026-10-27 10:00:00';
    openssl_sign('YO-TX-1001'.$date, $sig, $this->yoPrivateKey, OPENSSL_ALGO_SHA1);

    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'failure']), [
        'failed_transaction_reference' => 'YO-TX-1001', 'transaction_init_date' => $date, 'verification' => base64_encode($sig),
    ])->assertOk();

    expect($tx->fresh()->status)->toBe('failed')
        ->and($tx->fresh()->status_message)->toBe('The payer declined.')
        ->and($landlord->fresh()->subscription_status)->toBe('trialing');
});

test('callbacks for unknown payments are acknowledged and ignored', function () {
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), ['external_ref' => 'NOT-OURS', 'amount' => '5000'])->assertOk();
    $this->post(route('payments.callback', ['gateway' => 'nope', 'type' => 'ipn']))->assertNotFound();
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'weird']))->assertNotFound();
});

test('the callback URL needs no CSRF token or login', function () {
    // (Covered implicitly above, but make it explicit: a bare POST is accepted.)
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), [])->assertOk();
});

// --- Polling (backup) ---

test('the reconciler picks up a success when no IPN arrives', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $tx->update(['last_checked_at' => now()->subMinutes(2)]);
    $this->yoStatus = 'SUCCEEDED';

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($tx->fresh()->status)->toBe('succeeded')
        ->and($landlord->fresh()->subscription_status)->toBe('active');

    // Status checks used our reference / Yo!'s, for a PULL deposit.
    expect(collect($this->yoRequests)->last())
        ->toContain('<Method>actransactioncheckstatus</Method>')
        ->toContain('<TransactionReference>YO-TX-1001</TransactionReference>')
        ->toContain('<DepositTransactionType>PULL</DepositTransactionType>');
});

test('a payment Yo! never received fails only after the grace period', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $tx->update(['gateway_reference' => null, 'last_checked_at' => now()->subMinutes(2)]);
    $this->yoStatus = 'NOTFOUND';

    $this->artisan('payments:reconcile');
    expect($tx->fresh()->status)->toBe('pending');

    PaymentTransaction::whereKey($tx->id)->update(['created_at' => now()->subHour(), 'last_checked_at' => now()->subHour()]);
    $this->artisan('payments:reconcile');
    expect($tx->fresh()->status)->toBe('failed');

    // Looked up by our own reference when Yo!'s is unknown.
    expect(collect($this->yoRequests)->last())->toContain("<PrivateTransactionReference>{$tx->reference}</PrivateTransactionReference>");
});

test('the page polls the status endpoint, which checks with Yo! and is scoped to the landlord', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $tx->update(['last_checked_at' => now()->subMinute()]);
    $this->yoStatus = 'SUCCEEDED';

    $this->actingAs($admin)->getJson(route('subscription.payment-status', $tx->reference))
        ->assertOk()
        ->assertJson(['status' => 'succeeded', 'receipt' => '55501']);

    [, $otherAdmin] = subscriptionSetup();
    $this->actingAs($otherAdmin)->getJson(route('subscription.payment-status', $tx->reference))->assertNotFound();
});

// --- Subscription periods ---

test('paying early adds the new period to the end of the current one', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $landlord->update(['subscription_status' => 'active', 'current_period_end' => today()->addDays(10)]);

    $tx = startPayment($this, $admin, $plan, 'quarterly');
    $this->yoStatus = 'SUCCEEDED';
    $this->artisan('payments:reconcile', ['--reference' => $tx->reference]);

    expect($landlord->fresh()->current_period_end->toDateString())
        ->toBe(today()->addDays(10)->addMonthsNoOverflow(3)->toDateString())
        ->and($tx->fresh()->context['period_start'])->toBe(today()->addDays(10)->toDateString());
});

test('an expired subscription restarts from today', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $landlord->update(['subscription_status' => 'active', 'current_period_end' => today()->subDays(20)]);

    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'SUCCEEDED';
    $this->artisan('payments:reconcile', ['--reference' => $tx->reference]);

    expect($landlord->fresh()->current_period_end->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString());
});

// --- Staff mode and swappable gateways ---

test('staff in a landlord\'s portal can pay on the landlord\'s behalf', function () {
    [$landlord, , $plan] = subscriptionSetup();
    $staff = StaffUser::factory()->create();
    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord));

    $this->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0701234567'])
        ->assertRedirect(route('subscription.show'));

    $tx = PaymentTransaction::firstOrFail();
    expect($tx->landlord_id)->toBe($landlord->id)
        ->and($tx->initiated_by_staff_user_id)->toBe($staff->id)
        ->and($tx->msisdn)->toBe('256701234567');
});

test('another gateway can be plugged in through config alone', function () {
    config(['payments.default' => 'fake']);
    [$landlord, $admin, $plan] = subscriptionSetup();

    $tx = startPayment($this, $admin, $plan);
    expect($tx->gateway)->toBe('fake')->and($this->yoRequests)->toBe([]);

    PaymentTransaction::whereKey($tx->id)->update(['created_at' => now()->subMinute(), 'last_checked_at' => now()->subMinute()]);
    $this->artisan('payments:reconcile');

    expect($tx->fresh()->status)->toBe('succeeded')
        ->and($landlord->fresh()->subscription_status)->toBe('active');
});

test('payments are unavailable when the gateway has no credentials', function () {
    config(['payments.gateways.yo.api_username' => null]);
    [, $admin, $plan] = subscriptionSetup();

    $this->actingAs($admin)->get(route('subscription.show'))->assertSee("Mobile money payments aren't available yet", false);
    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertSessionHas('error');
});

// --- Connection problems ---

test('if Yo! cannot be reached at all, the payment fails at once instead of waiting', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();

    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'));

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'no payment request was sent'));

    expect(PaymentTransaction::first()->status)->toBe('failed')
        ->and($landlord->fresh()->subscription_status)->toBe('trialing');
});

test('a timeout after sending keeps the payment open for the status checks to settle', function () {
    [, $admin, $plan] = subscriptionSetup();

    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 45000 milliseconds'));

    startPayment($this, $admin, $plan);

    expect(PaymentTransaction::first()->status)->toBe('pending');
});

// --- Staff payments section and gateway log ---

test('every exchange with Yo! is logged, with the API password masked', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);

    $log = \App\Models\PaymentGatewayLog::where('payment_transaction_id', $tx->id)->firstOrFail();

    expect($log->action)->toBe('acdepositfunds')
        ->and($log->direction)->toBe('outbound')
        ->and($log->url)->toBe('https://sandbox.yo.co.ug/services/yopaymentsdev/task.php')
        ->and($log->response_status)->toBe(200)
        ->and($log->response_body)->toContain('<TransactionStatus>PENDING</TransactionStatus>')
        ->and($log->request_body)->toContain('<Method>acdepositfunds</Method>')
        ->and($log->request_body)->toContain('<APIPassword>********</APIPassword>')
        ->and($log->request_body)->toContain('<APIUsername>tes******</APIUsername>')
        ->and($log->request_body)->not->toContain('test-pass')
        ->and($log->duration_ms)->toBeInt();
});

test('connection failures are logged with the error', function () {
    [, $admin, $plan] = subscriptionSetup();
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect'));

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456']);

    $log = \App\Models\PaymentGatewayLog::firstOrFail();
    expect($log->error)->toContain('cURL error 7')->and($log->response_status)->toBeNull();
});

test('staff see payments with their details and full gateway log', function () {
    [$landlord, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'SUCCEEDED';
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), signedIpn($this, $tx))->assertOk();

    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.payments.index'))
        ->assertOk()
        ->assertSee($tx->reference)
        ->assertSee('Kololo Homes Ltd')
        ->assertSee('20,000');

    $page = $this->actingAs($staff, 'staff')->get(route('staff.payments.show', $tx))->assertOk();
    $page->assertSee('Payment request (acdepositfunds)')
        ->assertSee('Payment notification (IPN)')
        ->assertSee('signature verified')
        ->assertSee('Status check (actransactioncheckstatus)')
        ->assertSee('&lt;Method&gt;acdepositfunds&lt;/Method&gt;', false) // the XML, shown escaped
        ->assertSee('YO-TX-1001')
        ->assertSee('55501')
        ->assertDontSee('test-pass');
});

test('staff can ask Yo! for a payment\'s status now', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $staff = StaffUser::factory()->create();
    $this->yoStatus = 'SUCCEEDED';

    $this->actingAs($staff, 'staff')->post(route('staff.payments.check', $tx))
        ->assertSessionHas('success', 'Checked with the gateway: pending → succeeded.');

    expect($tx->fresh()->status)->toBe('succeeded')
        ->and(\App\Models\PaymentGatewayLog::where('payment_transaction_id', $tx->id)->where('action', 'actransactioncheckstatus')->exists())->toBeTrue();
});

test('staff can search payments by phone or reference, and filter by status', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan, phone: '0772 555 101');
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.payments.index', ['search' => '0772555101']))->assertSee($tx->reference);
    $this->actingAs($staff, 'staff')->get(route('staff.payments.index', ['search' => $tx->reference]))->assertSee($tx->reference);
    $this->actingAs($staff, 'staff')->get(route('staff.payments.index', ['status' => 'failed']))->assertDontSee($tx->reference);
});

test('callbacks that match no payment are listed for staff, and nothing is credited', function () {
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), ['external_ref' => 'SOMEONE-ELSE', 'amount' => '7000', 'msisdn' => '256700000001']);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.payments.index'))->assertSee('unmatched gateway callbacks');
    $this->actingAs($staff, 'staff')->get(route('staff.payments.unmatched'))
        ->assertOk()
        ->assertSee('no matching payment')
        ->assertSee('SOMEONE-ELSE');

    expect(PaymentTransaction::count())->toBe(0);
});

test('only signed-in staff can see payments', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);

    $this->get(route('staff.payments.index'))->assertRedirect(route('staff.login'));
    $this->actingAs($admin)->get(route('staff.payments.show', $tx))->assertRedirect(route('staff.login'));
});

// --- Network references (short, as in the payer's SMS) ---

test('the staff payments list shows the network reference, not Yo!\'s long one', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan); // 0772… → MTN
    $this->yoStatus = 'SUCCEEDED';
    $this->artisan('payments:reconcile', ['--reference' => $tx->reference]);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.payments.index'))
        ->assertSee('MTN MTN-777')
        ->assertDontSee('YO-TX-1001');

    // Yo!'s reference is still on the details page, and both are searchable.
    $this->actingAs($staff, 'staff')->get(route('staff.payments.show', $tx))->assertSee('Yo! transaction reference')->assertSee('YO-TX-1001');
    $this->actingAs($staff, 'staff')->get(route('staff.payments.index', ['search' => 'MTN-777']))->assertSee($tx->reference);
});

test('extra text Yo! appends to the network reference is dropped', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'SUCCEEDED';
    $this->yoMno = '330192910,,,PROMO OFFER';

    $this->artisan('payments:reconcile', ['--reference' => $tx->reference]);

    expect($tx->fresh()->network_reference)->toBe('330192910');
});

test('a genuine IPN fills in the network reference when the status check has none; a forged one does not', function () {
    [, $admin, $plan] = subscriptionSetup();
    $tx = startPayment($this, $admin, $plan);
    $this->yoStatus = 'PENDING'; // status check carries no MNO reference

    $forged = signedIpn($this, $tx, ['network_ref' => 'FAKE-1']);
    $forged['signature'] = base64_encode('nope');
    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), $forged);
    expect($tx->fresh()->network_reference)->toBeNull();

    $this->post(route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']), signedIpn($this, $tx, ['network_ref' => 'NET-555']));
    expect($tx->fresh()->network_reference)->toBe('NET-555');
});
