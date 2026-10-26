<?php

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsBundle;
use App\Models\SmsCreditTransaction;
use App\Models\SmsLog;
use App\Models\SmsPurchase;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\SmsCredits;
use App\Services\SmsNotificationService;
use App\Services\SmsSegments;
use App\Services\SmsService;
use App\Support\Settings;

/*
 * SMS reselling: Primora buys SMS from providers and sells prepaid credit
 * to landlords. Each 160-character part of a message uses one credit.
 */

beforeEach(fn () => config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]));
afterEach(fn () => CurrentLandlord::clear());

function smsLandlord(int $balance = 0): array
{
    $landlord = Landlord::factory()->create(['name' => 'Ntinda Flats']);
    if ($balance) {
        app(SmsCredits::class)->add($landlord->id, $balance, 'purchase', 'setup');
    }
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    return [$landlord->fresh(), $admin];
}

/** Calls the (protected) single place every tenant SMS goes through. */
function sendTenantSms(Landlord $landlord, string $message): SmsLog
{
    CurrentLandlord::set($landlord->id);
    $service = app(SmsNotificationService::class);
    (fn () => $this->sendAndLog('256772123456', $message, null, 'invoice_created', 'invoice', 1))->call($service);

    return SmsLog::withoutGlobalScopes()->latest('id')->firstOrFail();
}

// --- Counting and metering ---

test('messages are counted per 160-character part (GSM) or 70 (unicode)', function () {
    expect(SmsSegments::count(str_repeat('a', 160)))->toBe(1)
        ->and(SmsSegments::count(str_repeat('a', 161)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 306)))->toBe(2)
        ->and(SmsSegments::count(str_repeat('a', 307)))->toBe(3)
        ->and(SmsSegments::count(str_repeat('€', 80)))->toBe(1) // extended GSM chars count double
        ->and(SmsSegments::count(str_repeat('€', 81)))->toBe(2)
        ->and(SmsSegments::count('Habari 😊'))->toBe(1)
        ->and(SmsSegments::count(str_repeat('😊', 40)))->toBe(2);
});

test('sending an SMS uses one credit per part and records it', function () {
    $this->mock(SmsService::class)->shouldReceive('send')->once()->andReturn(true);
    [$landlord] = smsLandlord(10);

    $log = sendTenantSms($landlord, str_repeat('x', 200));

    expect($log->status)->toBe('sent')->and($log->segments)->toBe(2)->and($log->credits_charged)->toBe(2)
        ->and($landlord->fresh()->sms_balance)->toBe(8);
    $usage = SmsCreditTransaction::where('type', 'usage')->sole();
    expect($usage->quantity)->toBe(-2)->and($usage->balance_after)->toBe(8)->and($usage->sms_log_id)->toBe($log->id);
});

test('without enough credit the SMS is not sent and is logged as no credit', function () {
    $this->mock(SmsService::class)->shouldNotReceive('send');
    [$landlord] = smsLandlord(1);

    $log = sendTenantSms($landlord, str_repeat('x', 200));

    expect($log->status)->toBe('no_credit')->and($log->error)->toContain('needs 2, balance 1')
        ->and($landlord->fresh()->sms_balance)->toBe(1);
});

test('a failed send gives the credit back', function () {
    $this->mock(SmsService::class)->shouldReceive('send')->once()->andReturn(false);
    [$landlord] = smsLandlord(5);

    $log = sendTenantSms($landlord, 'Your invoice is ready.');

    expect($log->status)->toBe('failed')->and($landlord->fresh()->sms_balance)->toBe(5)
        ->and(SmsCreditTransaction::where('landlord_id', $landlord->id)->pluck('type')->sort()->values()->all())->toBe(['purchase', 'refund', 'usage']);
});

test('new landlords get the starter allowance set by staff', function () {
    Settings::set('sms.starter_credits', '75');
    $landlord = Landlord::factory()->create();

    app(SmsCredits::class)->grantStarter($landlord);

    expect($landlord->fresh()->sms_balance)->toBe(75)
        ->and(SmsCreditTransaction::where('landlord_id', $landlord->id)->sole()->type)->toBe('starter');

    Settings::set('sms.starter_credits', '0');
    $other = Landlord::factory()->create();
    expect(app(SmsCredits::class)->grantStarter($other))->toBeNull()->and($other->fresh()->sms_balance)->toBe(0);
});

test('signing up grants starter SMS', function () {
    \Illuminate\Support\Facades\Notification::fake();
    Settings::set('sms.starter_credits', '50');
    $this->post(route('signup'), [
        'company_name' => 'Bugolobi Homes', 'name' => 'Owner Person', 'email' => 'owner@bugolobi.test', 'phone' => '+256772000111',
        'password' => 'secret1234', 'password_confirmation' => 'secret1234',
    ])->assertRedirect();

    expect(Landlord::where('name', 'Bugolobi Homes')->firstOrFail()->sms_balance)->toBe(50);
});

// --- Landlord buying credit ---

test('a landlord admin sees their balance, bundles and a low-credit warning', function () {
    SmsBundle::create(['name' => 'Standard', 'quantity' => 500, 'price' => 17500, 'is_active' => true]);
    SmsBundle::create(['name' => 'Old', 'quantity' => 100, 'price' => 5000, 'is_active' => false]);
    [$landlord, $admin] = smsLandlord(0);

    $this->actingAs($admin)->get(route('sms-credits.show'))
        ->assertOk()->assertSee('Standard')->assertDontSee('>Old<', false)
        ->assertSee("You're out of SMS credit", false);

    // The banner shows elsewhere in the portal too.
    $this->actingAs($admin)->get(route('dashboard'))->assertSee('Buy SMS');
});

test('buying a bundle by mobile money adds the SMS once paid', function () {
    $bundle = SmsBundle::create(['name' => 'Standard', 'quantity' => 500, 'price' => 17500, 'is_active' => true]);
    [$landlord, $admin] = smsLandlord(3);

    $this->actingAs($admin)->post(route('sms-credits.buy'), ['bundle_id' => $bundle->id, 'phone' => '0772 123 456'])
        ->assertRedirect(route('sms-credits.show'));

    $tx = PaymentTransaction::latest('id')->sole();
    expect($tx->purpose)->toBe('sms')->and((float) $tx->amount)->toBe(17500.0)
        ->and($tx->reference)->toStartWith('SMS-')->and($tx->narrative)->toContain('PRIMORA:SMS')
        ->and($landlord->fresh()->sms_balance)->toBe(3);

    PaymentTransaction::whereKey($tx->id)->update(['last_checked_at' => now()->subMinute()]);
    $this->actingAs($admin)->getJson(route('sms-credits.payment-status', $tx->reference))
        ->assertOk()->assertJson(['status' => 'succeeded', 'sms_balance' => 503]);

    // Applied exactly once, however often it's checked.
    $this->artisan('payments:reconcile');
    app(\App\Payments\PaymentService::class)->refresh($tx->fresh());
    expect($landlord->fresh()->sms_balance)->toBe(503)
        ->and(SmsCreditTransaction::where('payment_transaction_id', $tx->id)->count())->toBe(1);
});

test('hidden bundles cannot be bought', function () {
    $bundle = SmsBundle::create(['name' => 'Old', 'quantity' => 100, 'price' => 5000, 'is_active' => false]);
    [, $admin] = smsLandlord();

    $this->actingAs($admin)->from(route('sms-credits.show'))
        ->post(route('sms-credits.buy'), ['bundle_id' => $bundle->id, 'phone' => '0772123456'])
        ->assertSessionHas('error');
    expect(PaymentTransaction::count())->toBe(0);
});

test('landlords only see their own payment status', function () {
    [$landlord] = smsLandlord();
    [, $otherAdmin] = smsLandlord();
    $tx = PaymentTransaction::create([
        'reference' => 'SMS-261027-ABCDEF', 'purpose' => 'sms', 'landlord_id' => $landlord->id, 'gateway' => 'fake',
        'amount' => 5000, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'pending',
    ]);

    $this->actingAs($otherAdmin)->getJson(route('sms-credits.payment-status', $tx->reference))->assertNotFound();
});

test('non-admin landlord users cannot buy SMS', function () {
    [$landlord] = smsLandlord();
    $user = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'user']);

    $this->actingAs($user)->get(route('sms-credits.show'))->assertForbidden();
});

// --- Staff ---

test('staff record provider purchases and see the stock position', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.purchases'])->create();
    [$landlord] = smsLandlord(200);

    $this->actingAs($staff, 'staff')->post(route('staff.sms.purchases.store'), [
        'provider' => 'Marz', 'quantity' => 10000, 'total_cost' => 250000, 'purchased_on' => today()->toDateString(), 'reference' => 'INV-9',
    ])->assertSessionHas('success');

    $purchase = SmsPurchase::sole();
    expect($purchase->recorded_by_staff_user_id)->toBe($staff->id)->and($purchase->unitCost())->toBe(25.0);

    $stats = \App\Http\Controllers\Staff\SmsController::stockStats();
    expect($stats['purchased'])->toBe(10000)->and($stats['held'])->toBe(Landlord::sum('sms_balance'))
        ->and($stats['unallocated'])->toBe(10000 - (int) Landlord::sum('sms_balance'));

    $this->actingAs($staff, 'staff')->get(route('staff.sms.index'))->assertOk()->assertSee('Marz')->assertSee('INV-9');
});

test('staff manage bundles; a bundle already bought is hidden, not deleted', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.manage'])->create();

    $this->actingAs($staff, 'staff')->post(route('staff.sms.bundles.store'), ['name' => 'Business', 'quantity' => 2000, 'price' => 60000, 'is_active' => '1'])
        ->assertSessionHas('success');
    $bundle = SmsBundle::sole();
    expect($bundle->is_active)->toBeTrue()->and($bundle->unitPrice())->toBe(30.0);

    [$landlord] = smsLandlord();
    PaymentTransaction::create([
        'reference' => 'SMS-261027-BUNDLE', 'purpose' => 'sms', 'landlord_id' => $landlord->id, 'gateway' => 'fake', 'amount' => 60000,
        'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'context' => ['bundle_id' => $bundle->id, 'quantity' => 2000],
    ]);

    $this->actingAs($staff, 'staff')->delete(route('staff.sms.bundles.destroy', $bundle))->assertSessionHas('success');
    expect($bundle->fresh())->not->toBeNull()->and($bundle->fresh()->is_active)->toBeFalse();

    $unsold = SmsBundle::create(['name' => 'Trial', 'quantity' => 10, 'price' => 500, 'is_active' => true]);
    $this->actingAs($staff, 'staff')->delete(route('staff.sms.bundles.destroy', $unsold));
    expect($unsold->fresh())->toBeNull();
});

test('staff change SMS settings', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.manage'])->create();

    $this->actingAs($staff, 'staff')->put(route('staff.sms.settings.update'), ['starter_credits' => 25, 'low_balance_threshold' => 40])
        ->assertSessionHas('success');

    Settings::flush();
    expect(Settings::int('sms.starter_credits'))->toBe(25)->and(app(SmsCredits::class)->lowBalanceThreshold())->toBe(40);
});

test('staff adjust a balance with a reason, never below zero', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.credits'])->create();
    [$landlord] = smsLandlord(10);

    $this->actingAs($staff, 'staff')->post(route('staff.sms.adjust', $landlord), ['quantity' => 40, 'reason' => 'Goodwill for outage'])
        ->assertSessionHas('success');
    expect($landlord->fresh()->sms_balance)->toBe(50);
    $entry = SmsCreditTransaction::where('type', 'adjustment')->sole();
    expect($entry->staff_user_id)->toBe($staff->id)->and($entry->note)->toBe('Goodwill for outage');

    $this->actingAs($staff, 'staff')->post(route('staff.sms.adjust', $landlord), ['quantity' => -60, 'reason' => 'Correction'])
        ->assertSessionHas('error');
    $this->actingAs($staff, 'staff')->post(route('staff.sms.adjust', $landlord), ['quantity' => 5])->assertSessionHasErrors('reason');
    expect($landlord->fresh()->sms_balance)->toBe(50);
});

test('staff record an offline SMS sale, which adds credit and counts as revenue', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.credits', 'revenue.view'])->create();
    [$landlord] = smsLandlord();

    $this->actingAs($staff, 'staff')->post(route('staff.sms.sales.store', $landlord), [
        'quantity' => 1000, 'amount' => 30000, 'method' => 'cash', 'received_on' => today()->toDateString(), 'reference' => 'RCPT-7',
    ])->assertSessionHas('success');

    $tx = PaymentTransaction::sole();
    expect($tx->purpose)->toBe('sms')->and($tx->gateway)->toBe('manual')->and($tx->status)->toBe('succeeded')
        ->and($tx->initiated_by_staff_user_id)->toBe($staff->id)
        ->and($landlord->fresh()->sms_balance)->toBe(1000);

    $this->actingAs($staff, 'staff')->get(route('staff.sms.landlord', $landlord))->assertOk()->assertSee('RCPT-7', false)->assertSee($staff->name);
});

test('staff see landlord balances, lowest first', function () {
    $staff = StaffUser::factory()->withPermissions(['sms.view'])->create();
    Landlord::factory()->create(['name' => 'Rich Estates']);
    Landlord::query()->where('name', 'Rich Estates')->update(['sms_balance' => 900]);
    Landlord::factory()->create(['name' => 'Empty Estates']);

    $this->actingAs($staff, 'staff')->get(route('staff.sms.landlords'))->assertOk()->assertSeeInOrder(['Empty Estates', 'Rich Estates']);
    $this->actingAs($staff, 'staff')->get(route('staff.sms.landlords', ['balance' => 'empty']))->assertSee('Empty Estates')->assertDontSee('Rich Estates');
});

// --- Revenue ---

test('revenue splits subscriptions and SMS sales, and costs SMS sent at the average provider price', function () {
    $staff = StaffUser::factory()->withPermissions(['revenue.view'])->create();
    [$landlord] = smsLandlord(100);
    $mk = fn ($ref, $purpose, $amount, $at, $status = 'succeeded') => PaymentTransaction::create([
        'reference' => $ref, 'purpose' => $purpose, 'landlord_id' => $landlord->id, 'gateway' => 'yo', 'amount' => $amount,
        'msisdn' => '256772123456', 'narrative' => 'x', 'status' => $status, 'completed_at' => $at,
    ]);
    $mk('SUB-1', 'subscription', 45000, now()->startOfMonth()->addHour());
    $mk('SMS-1', 'sms', 17500, now());
    $mk('SMS-2', 'sms', 99999, now(), 'failed');           // not revenue
    $mk('SUB-0', 'subscription', 15000, now()->subMonthNoOverflow()->startOfMonth()->addDay()); // last month

    SmsPurchase::create(['provider' => 'Marz', 'quantity' => 1000, 'total_cost' => 20000, 'purchased_on' => today()]);
    \Illuminate\Support\Facades\DB::table('sms_credit_transactions')->insert([
        'landlord_id' => $landlord->id, 'type' => 'usage', 'quantity' => -300, 'balance_after' => 0, 'created_at' => now(),
    ]);

    $response = $this->actingAs($staff, 'staff')->get(route('staff.revenue.index'))->assertOk();

    expect($response->viewData('current')['total'])->toBe(62500.0)
        ->and($response->viewData('current')['streams']['subscription']['amount'])->toBe(45000.0)
        ->and($response->viewData('current')['streams']['sms']['amount'])->toBe(17500.0)
        ->and($response->viewData('previous')['total'])->toBeGreaterThanOrEqual(0.0)
        ->and($response->viewData('smsSent'))->toBe(300)
        ->and($response->viewData('smsCost'))->toBe(6000.0); // 300 × UGX 20
    $response->assertSee('UGX 62,500')->assertSee('UGX 11,500'); // margin 17,500 − 6,000

    $lastMonth = $this->actingAs($staff, 'staff')->get(route('staff.revenue.index', ['period' => 'last_month']));
    expect($lastMonth->viewData('current')['total'])->toBe(15000.0);

    $months = collect($response->viewData('months'));
    expect($months)->toHaveCount(12)->and($months->last()['subscription'])->toBe(45000.0);
});

test('revenue needs its own permission', function () {
    $staff = StaffUser::factory()->withPermissions(['payments.view', 'subscriptions.view', 'sms.view'])->create();

    $this->actingAs($staff, 'staff')->get(route('staff.revenue.index'))->assertForbidden();
});
