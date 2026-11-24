<?php

use App\Accounting\Ledger;
use App\Accounting\Postings;
use App\Http\Controllers\PayoutController;
use App\Mail\ActionCodeMail;
use App\Mail\NoticeMail;
use App\Models\CollectionFeeTier;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Landlord;
use App\Models\LedgerAccount;
use App\Models\Liquidation;
use App\Models\PaymentGatewayLog;
use App\Models\PaymentTransaction;
use App\Models\PayoutAccount;
use App\Models\SmsPurchase;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\Liquidations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Landlord payouts (liquidations) and Primora's double-entry books.
 */

beforeEach(function () {
    config(['payments.default' => 'fake', 'payments.gateways.fake.succeed_after_seconds' => 0]);
    Mail::fake();
    \App\Support\Settings::flush();
});
afterEach(fn () => CurrentLandlord::clear());

function ledger(): Ledger
{
    return app(Ledger::class);
}

function balanced(): bool
{
    return bccomp((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 2) === 0;
}

/** A landlord Primora collects for, with an admin, and UGX $collected of rent collected (posted to the books). */
function payoutLandlord(float $collected = 500000): array
{
    $landlord = Landlord::factory()->create(['collect_payments' => true, 'contact_email' => 'owner@ntinda.test']);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id, 'password' => 'secret-pass-1']);

    if ($collected > 0) {
        $tx = PaymentTransaction::create([
            'reference' => 'RNT-'.fake()->unique()->bothify('######-??????'), 'purpose' => 'rent', 'landlord_id' => $landlord->id, 'gateway' => 'fake',
            'amount' => $collected, 'fee_amount' => 0, 'fee_bearer' => 'landlord', 'landlord_amount' => $collected,
            'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => now(), 'context' => ['tenant_name' => 'Tenant'],
        ]);
        app(Postings::class)->paymentSucceeded($tx);
    }

    return [$landlord, $admin];
}

function verifiedAccount(Landlord $landlord, string $type = 'mobile_money'): PayoutAccount
{
    CurrentLandlord::set($landlord->id);
    $account = PayoutAccount::create($type === 'mobile_money'
        ? ['type' => 'mobile_money', 'account_name' => 'Jane Owner', 'msisdn' => '256772555666', 'status' => 'verified']
        : ['type' => 'bank', 'account_name' => 'Ntinda Flats Ltd', 'bank_name' => 'Stanbic', 'account_number' => '9030012345678', 'status' => 'verified']);
    CurrentLandlord::clear();

    return $account;
}

/** Add a payout account the way a landlord does: details + password, then the emailed code. */
function addPayoutAccount($test, User $user, array $details)
{
    $test->travel(15)->minutes(); // past the resend wait and hourly cap
    $response = $test->actingAs($user, 'web')->post(route('payouts.accounts.store'), $details + ['password' => 'secret-pass-1']);
    if (!session()->has(PayoutController::PENDING_ACCOUNT)) {
        return $response;
    }

    return $test->actingAs($user, 'web')->post(route('payouts.accounts.confirm'), ['code' => Mail::sent(ActionCodeMail::class)->last()->code]);
}

/** Request a payout the way a landlord does: details + password, then the emailed code. */
function requestPayout($test, User $user, array $details)
{
    $test->travel(15)->minutes(); // past the resend wait and hourly cap
    $response = $test->actingAs($user, 'web')->post(route('payouts.store'), $details + ['password' => 'secret-pass-1']);
    if (!session()->has(PayoutController::PENDING_PAYOUT)) {
        return $response;
    }

    return $test->actingAs($user, 'web')->post(route('payouts.confirm'), ['code' => Mail::sent(ActionCodeMail::class)->last()->code]);
}

function payoutBands(): void
{
    CollectionFeeTier::create(['kind' => 'payout_mobile_money', 'min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1500]);
    CollectionFeeTier::create(['kind' => 'payout_bank', 'min_amount' => 0, 'max_amount' => null, 'fixed_fee' => 5000]);
}

function staffWith(array $permissions): StaffUser
{
    return StaffUser::factory()->withPermissions($permissions)->create();
}

// --- Books ------------------------------------------------------------------

test('collections post to the books: rent is owed to the landlord, subscriptions and SMS are income', function () {
    CollectionFeeTier::create(['kind' => 'collection', 'min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1000]);
    $landlord = Landlord::factory()->create();
    $mk = fn ($purpose, $amount, $extra = []) => PaymentTransaction::create($extra + [
        'reference' => strtoupper($purpose).'-'.fake()->unique()->numerify('######'), 'purpose' => $purpose, 'landlord_id' => $landlord->id, 'gateway' => 'yo',
        'amount' => $amount, 'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => now(),
    ]);

    $postings = app(Postings::class);
    $postings->paymentSucceeded($mk('rent', 300000, ['fee_amount' => 1000, 'fee_bearer' => 'landlord', 'landlord_amount' => 299000]));
    $postings->paymentSucceeded($mk('subscription', 45000));
    $postings->paymentSucceeded($mk('sms', 17500, ['gateway' => 'manual', 'context' => ['method' => 'cash']]));

    expect(ledger()->landlordPayable($landlord->id))->toBe(299000.0)
        ->and(ledger()->balance('collection_fee_income'))->toBe(1000.0)
        ->and(ledger()->balance('subscription_income'))->toBe(45000.0)
        ->and(ledger()->balance('sms_income'))->toBe(17500.0)
        ->and(ledger()->balance('cash'))->toBe(17500.0)               // offline: no Yo! charge
        ->and(ledger()->balance('gateway_charges'))->toBe(2000.0)     // Yo! charge on the two Yo! collections
        ->and(ledger()->balance('yo_pool'))->toBe(300000.0 + 45000 - 2000)
        ->and(balanced())->toBeTrue();

    // Re-running (e.g. a backfill) never double-posts.
    $count = JournalEntry::count();
    $this->artisan('books:backfill')->assertSuccessful();
    expect(JournalEntry::count())->toBe($count);
});

test('a payment confirmed by the gateway is posted automatically', function () {
    [$landlord, $admin] = payoutLandlord(0);
    $plan = \App\Models\Plan::factory()->create(['monthly_price' => 20000]);

    $this->actingAs($admin)->post(route('subscription.pay'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'phone' => '0772123456']);
    $tx = PaymentTransaction::sole();
    app(\App\Payments\PaymentService::class)->refresh($tx);

    expect(JournalEntry::where('source_type', 'payment_transaction')->where('source_id', $tx->id)->exists())->toBeTrue()
        ->and(ledger()->balance('subscription_income'))->toBe((float) $tx->fresh()->amount);
});

test('SMS purchases are expenses, re-posted when edited and reversed when deleted', function () {
    $purchase = SmsPurchase::create(['provider' => 'Marz', 'quantity' => 1000, 'total_cost' => 20000, 'paid_from' => 'bank', 'purchased_on' => today()]);
    expect(ledger()->balance('sms_purchases'))->toBe(20000.0)->and(ledger()->balance('bank'))->toBe(-20000.0);

    $purchase->update(['total_cost' => 25000, 'paid_from' => 'yo_pool']);
    expect(ledger()->balance('sms_purchases'))->toBe(25000.0)->and(ledger()->balance('bank'))->toBe(0.0)->and(ledger()->balance('yo_pool'))->toBe(-25000.0);

    $purchase->update(['notes' => 'unrelated change']);
    expect(JournalEntry::where('source_type', 'sms_purchase')->count())->toBe(3); // posted, reversal, re-posted: nothing new

    $purchase->delete();
    expect(ledger()->balance('sms_purchases'))->toBe(0.0)->and(balanced())->toBeTrue();
});

test('Yo!-style bands: a flat fee for small amounts, a percentage above', function () {
    // Yo! deposits: 500–10,000 → flat UGX 300; 10,001 and above → 2%.
    CollectionFeeTier::create(['kind' => 'collection', 'min_amount' => 500, 'max_amount' => 10000, 'fixed_fee' => 300, 'percent_fee' => 0]);
    CollectionFeeTier::create(['kind' => 'collection', 'min_amount' => 10001, 'max_amount' => null, 'fixed_fee' => 0, 'percent_fee' => 2]);
    $fees = app(\App\Services\CollectionFees::class);

    expect($fees->feeFor(500))->toBe(300.0)
        ->and($fees->feeFor(10000))->toBe(300.0)
        ->and($fees->feeFor(10001))->toBe(201.0)       // 2% = 200.02, rounded up to the next shilling
        ->and($fees->feeFor(50000))->toBe(1000.0)
        ->and($fees->feeFor(500000))->toBe(10000.0)
        ->and($fees->feeFor(499))->toBe(0.0);          // below every band

    // Landlord bears it: tenant pays the amount due; the landlord gets it less the fee.
    $landlord = Landlord::factory()->create(['pass_collection_fee' => false]);
    expect($fees->quote($landlord, 300000))->toMatchArray(['charge' => 300000.0, 'fee' => 6000.0, 'landlord_amount' => 294000.0]);

    // Tenant pays it: 2% is charged on the total collected (which includes the fee itself).
    $landlord->pass_collection_fee = true;
    $q = app(\App\Services\CollectionFees::class)->quote($landlord, 300000);
    expect($q['fee'])->toBe(6123.0)->and($q['charge'])->toBe(306123.0)->and($q['landlord_amount'])->toBe(300000.0)
        ->and(app(\App\Services\CollectionFees::class)->feeFor($q['charge']))->toBeLessThanOrEqual($q['fee']); // covers Yo!'s charge on the total

    // Adding the flat 300 would push 9,800 over 10,000, where Yo! only takes ~2%:
    // the tenant pays the smallest fee that still covers Yo!'s charge on the total.
    $q = app(\App\Services\CollectionFees::class)->quote($landlord, 9800);
    expect($q['fee'])->toBe(201.0)->and($q['charge'])->toBe(10001.0)
        ->and(app(\App\Services\CollectionFees::class)->feeFor($q['charge']))->toBe(201.0);
    expect(app(\App\Services\CollectionFees::class)->quote($landlord, 5000)['fee'])->toBe(300.0); // stays in the flat band

    // Shown the way staff entered them.
    $bands = CollectionFeeTier::orderBy('min_amount')->get();
    expect($bands[0]->feeLabel())->toBe('300')->and($bands[1]->feeLabel())->toBe('2%');
});

test('the ledger refuses unbalanced entries', function () {
    expect(fn () => ledger()->post(today(), 'Bad', [['account' => 'bank', 'debit' => 100], ['account' => 'equity', 'credit' => 90]]))
        ->toThrow(InvalidArgumentException::class);
    expect(JournalEntry::count())->toBe(0);
});

test('staff record expenses, transfers and journals; manual entries can be reversed', function () {
    $staff = staffWith(['books.post']);
    $bank = LedgerAccount::key('bank');

    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.store'), ['entry_date' => today()->toDateString(), 'description' => 'Opening bank balance', 'lines' => [
        ['account_id' => $bank->id, 'debit' => 1000000], ['account_id' => LedgerAccount::key('equity')->id, 'credit' => 1000000],
    ]])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.books.expenses.store'), [
        'entry_date' => today()->toDateString(), 'expense_account_id' => LedgerAccount::key('other_expenses')->id, 'paid_from_id' => $bank->id, 'amount' => 150000, 'description' => 'Office rent',
    ])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.books.transfers.store'), [
        'entry_date' => today()->toDateString(), 'from_id' => $bank->id, 'to_id' => LedgerAccount::key('cash')->id, 'amount' => 50000,
    ])->assertSessionHas('success');

    expect(ledger()->balance('bank'))->toBe(800000.0)->and(ledger()->balance('cash'))->toBe(50000.0);

    // Unbalanced journals and landlord funds are refused.
    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.store'), ['entry_date' => today()->toDateString(), 'description' => 'x', 'lines' => [
        ['account_id' => $bank->id, 'debit' => 10], ['account_id' => LedgerAccount::key('equity')->id, 'credit' => 9],
    ]])->assertSessionHas('error');
    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.store'), ['entry_date' => today()->toDateString(), 'description' => 'x', 'lines' => [
        ['account_id' => $bank->id, 'debit' => 10], ['account_id' => LedgerAccount::key('landlord_payable')->id, 'credit' => 10],
    ]])->assertSessionHas('error');

    $expense = JournalEntry::where('description', 'Office rent')->sole();
    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.reverse', $expense), ['reason' => 'Wrong month'])->assertSessionHas('success');
    expect(ledger()->balance('bank'))->toBe(950000.0)->and($expense->fresh()->reversed_at)->not->toBeNull()->and(balanced())->toBeTrue();

    // P&L
    $pnl = ledger()->profitAndLoss(now()->startOfMonth()->toImmutable(), now()->toImmutable());
    expect($pnl['total_expense'])->toBe(0.0);
});

test('books pages render', function () {
    $staff = staffWith(['books.view']);
    payoutLandlord(100000);

    foreach (['staff.books.index', 'staff.books.accounts', 'staff.books.journal', 'staff.books.pnl'] as $route) {
        $this->actingAs($staff, 'staff')->get(route($route))->assertOk();
    }
    $this->actingAs($staff, 'staff')->get(route('staff.books.ledger', LedgerAccount::key('landlord_payable')))->assertOk()->assertSee('Owed to each landlord');
});

// --- Payout accounts --------------------------------------------------------

test('landlords add payout accounts with their password; staff verify them', function () {
    [$landlord, $admin] = payoutLandlord();
    $verifier = staffWith(['liquidations.approve']);

    $this->actingAs($admin)->post(route('payouts.accounts.store'), ['type' => 'mobile_money', 'account_name' => 'Jane', 'msisdn' => '0772555666', 'password' => 'wrong'])
        ->assertSessionHasErrors('password');
    addPayoutAccount($this, $admin, ['type' => 'mobile_money', 'account_name' => 'Jane', 'msisdn' => '0772555666'])->assertSessionHas('success');

    $account = PayoutAccount::withoutGlobalScopes()->sole();
    expect($account->status)->toBe('pending')->and($account->msisdn)->toBe('256772555666');
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($verifier->email));

    // Not usable until verified.
    $this->actingAs($admin)->post(route('payouts.store'), ['payout_account_id' => $account->id, 'amount' => 100000, 'password' => 'secret-pass-1'])->assertSessionHasErrors('request');

    $this->post(route('logout'));
    $this->actingAs($verifier, 'staff')->post(route('staff.payout-accounts.verify', $account->id))->assertSessionHas('success');
    expect($account->fresh()->status)->toBe('verified')->and($account->fresh()->reviewed_by_staff_user_id)->toBe($verifier->id);
});

test('mobile money payout numbers are limited per landlord; staff can change the limit', function () {
    [$landlord, $admin] = payoutLandlord();
    $add = fn (string $type, string $msisdn = '') => addPayoutAccount($this, $admin, $type === 'bank'
        ? ['type' => 'bank', 'account_name' => 'Jane', 'bank_name' => 'Stanbic', 'account_number' => '9030012345678']
        : ['type' => 'mobile_money', 'account_name' => 'Jane', 'msisdn' => $msisdn]);

    // Default limit is 2.
    $add('mobile_money', '0772555661')->assertSessionHas('success');
    $add('mobile_money', '0772555662')->assertSessionHas('success');
    $add('mobile_money', '0772555663')->assertSessionHasErrors('msisdn');
    $add('bank')->assertSessionHas('success'); // banks aren't limited
    expect(PayoutAccount::withoutGlobalScopes()->where('type', 'mobile_money')->count())->toBe(2);
    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('You already have 2 of 2 mobile money numbers');

    // Rejected and removed numbers free a slot.
    PayoutAccount::withoutGlobalScopes()->where('msisdn', '256772555661')->update(['status' => 'disabled']);
    $add('mobile_money', '0772555663')->assertSessionHas('success');

    // Another landlord has their own allowance.
    $other = User::factory()->admin()->create(['landlord_id' => Landlord::factory()->create(['collect_payments' => true])->id, 'password' => 'secret-pass-1']);
    $this->post(route('logout'));
    addPayoutAccount($this, $other, ['type' => 'mobile_money', 'account_name' => 'Ann', 'msisdn' => '0701555661'])->assertSessionHas('success');

    // Staff raise the limit.
    $this->post(route('logout'));
    $viewer = staffWith(['liquidations.view']);
    $this->actingAs($viewer, 'staff')->put(route('staff.payout-accounts.settings.update'), ['max_mobile_money_accounts' => 3])->assertForbidden();
    $approver = staffWith(['liquidations.view', 'liquidations.approve']);
    $this->actingAs($approver, 'staff')->get(route('staff.payout-accounts.index'))->assertOk()->assertSee('Mobile money limit');
    $this->actingAs($approver, 'staff')->put(route('staff.payout-accounts.settings.update'), ['max_mobile_money_accounts' => 0])->assertSessionHasErrors('max_mobile_money_accounts');
    $this->actingAs($approver, 'staff')->put(route('staff.payout-accounts.settings.update'), ['max_mobile_money_accounts' => 3])->assertSessionHas('success');
    expect(PayoutAccount::mobileMoneyLimit())->toBe(3);

    auth('staff')->logout();
    $add('mobile_money', '0772555664')->assertSessionHas('success');
    $add('mobile_money', '0772555665')->assertSessionHasErrors('msisdn');
});

test('a new payout account is only added once the user enters the code sent to them', function () {
    [, $admin] = payoutLandlord();
    $admin->update(['phone' => '0772999888']);
    $details = ['type' => 'mobile_money', 'account_name' => 'Jane', 'msisdn' => '0772555666', 'password' => 'secret-pass-1'];

    $this->actingAs($admin)->post(route('payouts.accounts.store'), $details)->assertRedirect(route('payouts.index'))->assertSessionHas('success');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(0);
    Mail::assertSent(ActionCodeMail::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->action, 'MTN 0772 555 666'));
    Mail::assertNotSent(NoticeMail::class);
    $code = Mail::sent(ActionCodeMail::class)->last()->code;
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('Confirm it')->assertSee('MTN 0772 555 666')->assertSee('07•• ••• 888');

    // A wrong code adds nothing; a resend straight away is refused.
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $wrong])->assertSessionHasErrors('code');
    $this->actingAs($admin)->post(route('payouts.accounts.resend'))->assertSessionHas('error');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(0);

    // The right code adds it, once.
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $code])->assertSessionHas('success');
    expect(PayoutAccount::withoutGlobalScopes()->sole()->status)->toBe('pending');
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($admin->email));
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $code])->assertSessionHas('error');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(1);
});

test('payout account codes lock after too many wrong tries and expire', function () {
    [, $admin] = payoutLandlord();
    $details = ['type' => 'bank', 'account_name' => 'Jane', 'bank_name' => 'Stanbic', 'account_number' => '9030012345678', 'password' => 'secret-pass-1'];

    $this->actingAs($admin)->post(route('payouts.accounts.store'), $details);
    $code = Mail::sent(ActionCodeMail::class)->last()->code;
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $_) {
        $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $wrong]);
    }
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $code])->assertSessionHasErrors('code');

    // A new code works.
    $this->travel(2)->minutes();
    $this->actingAs($admin)->post(route('payouts.accounts.resend'))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => Mail::sent(ActionCodeMail::class)->last()->code])->assertSessionHas('success');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(1);

    // Codes expire.
    $this->travel(5)->minutes();
    $this->actingAs($admin)->post(route('payouts.accounts.store'), ['account_number' => '9030012345679'] + $details);
    $code = Mail::sent(ActionCodeMail::class)->last()->code;
    $this->travel(11)->minutes();
    $this->actingAs($admin)->post(route('payouts.accounts.confirm'), ['code' => $code])->assertSessionHas('error');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(1);
});

// --- Requests -----------------------------------------------------------------

test('requests stay within the available balance and hold it while in progress', function () {
    payoutBands();
    [$landlord, $admin] = payoutLandlord(500000);
    $account = verifiedAccount($landlord);

    requestPayout($this, $admin, ['payout_account_id' => $account->id, 'amount' => 600000])->assertSessionHasErrors('request');
    Mail::assertNotSent(ActionCodeMail::class); // no code for a request that can't go through
    requestPayout($this, $admin, ['payout_account_id' => $account->id, 'amount' => 1000])->assertSessionHasErrors('request'); // doesn't cover the UGX 1,500 charge
    requestPayout($this, $admin, ['payout_account_id' => $account->id, 'amount' => 300000])->assertSessionHas('payout_received');

    $l = Liquidation::withoutGlobalScopes()->sole();
    expect($l->status)->toBe('pending')->and((float) $l->fee)->toBe(1500.0)->and((float) $l->net_amount)->toBe(298500.0)
        ->and($l->destination['msisdn'])->toBe('256772555666')
        ->and(app(Liquidations::class)->available($landlord->id))->toBe(200000.0);

    // The rest is still available; more than that isn't.
    requestPayout($this, $admin, ['payout_account_id' => $account->id, 'amount' => 250000])->assertSessionHasErrors('request');

    // Cancelling releases it.
    $this->actingAs($admin)->post(route('payouts.cancel', $l))->assertSessionHas('success');
    expect(app(Liquidations::class)->available($landlord->id))->toBe(500000.0);

    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('UGX 500,000')->assertSee('Cancelled');
});

test('the smallest payout is where the lowest payout fee tier for that method starts', function () {
    [$landlord, $admin] = payoutLandlord(1800);
    CollectionFeeTier::create(['kind' => 'payout_mobile_money', 'min_amount' => 500, 'max_amount' => 5000, 'fixed_fee' => 100]);
    CollectionFeeTier::create(['kind' => 'payout_mobile_money', 'min_amount' => 5001, 'max_amount' => null, 'fixed_fee' => 1000]);
    CollectionFeeTier::create(['kind' => 'payout_bank', 'min_amount' => 5000, 'max_amount' => null, 'fixed_fee' => 5000]);
    $liq = app(Liquidations::class);
    expect($liq->minFor('mobile_money'))->toBe(500)->and($liq->minFor('bank'))->toBe(5000);

    // Only a bank account: 1,800 is below the bank tier, so no request form.
    $bank = verifiedAccount($landlord, 'bank');
    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('below the minimum payout of UGX 5,000');

    // With a mobile money account the form shows, and 1,800 can go to mobile money but not to the bank.
    $mm = verifiedAccount($landlord);
    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('Request everything available');
    requestPayout($this, $admin, ['payout_account_id' => $bank->id, 'amount' => 1800])->assertSessionHasErrors('request');
    requestPayout($this, $admin, ['payout_account_id' => $mm->id, 'amount' => 400])->assertSessionHasErrors('request');
    requestPayout($this, $admin, ['payout_account_id' => $mm->id, 'amount' => 1800])->assertSessionHas('payout_received');

    // Staff move the lowest tier: the minimum follows.
    CollectionFeeTier::where('kind', 'payout_mobile_money')->where('min_amount', 500)->update(['min_amount' => 1000]);
    expect(app(Liquidations::class)->minFor('mobile_money'))->toBe(1000);
});

test('a payout request needs the code sent to the user; the landlord is told it was received', function () {
    payoutBands();
    [$landlord, $admin] = payoutLandlord(500000);
    $account = verifiedAccount($landlord);
    $approver = staffWith(['liquidations.approve']);

    $this->actingAs($admin)->post(route('payouts.store'), ['payout_account_id' => $account->id, 'amount' => 300000, 'note' => 'Rent for March', 'password' => 'secret-pass-1'])
        ->assertRedirect(route('payouts.index'));
    expect(Liquidation::withoutGlobalScopes()->count())->toBe(0);
    Mail::assertSent(ActionCodeMail::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->action, 'UGX 300,000'));
    $code = Mail::sent(ActionCodeMail::class)->last()->code;
    $wrong = $code === '000000' ? '111111' : '000000';

    // The code screen shows what is being confirmed.
    $this->actingAs($admin)->get(route('payouts.index'))->assertOk()->assertSee('Confirm your payout request')->assertSee('UGX 298,500');

    $this->actingAs($admin)->post(route('payouts.confirm'), ['code' => $wrong])->assertSessionHasErrors('code');
    expect(Liquidation::withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($admin)->post(route('payouts.confirm'), ['code' => $code])->assertRedirect(route('payouts.index'))->assertSessionHas('payout_received');
    $l = Liquidation::withoutGlobalScopes()->sole();
    expect($l->request_note)->toBe('Rent for March')->and((float) $l->amount)->toBe(300000.0);

    // Received: email to the landlord and to Primora, and a receipt on screen.
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->subjectLine, "{$l->reference} received"));
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('owner@ntinda.test') && str_contains($m->subjectLine, 'received'));
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($approver->email));
    $this->actingAs($admin)->get(route('payouts.index'))->assertSee('Payout request received')->assertSee($l->reference);

    // The code can't be used again.
    $this->actingAs($admin)->post(route('payouts.confirm'), ['code' => $code])->assertSessionHas('error');
    expect(Liquidation::withoutGlobalScopes()->count())->toBe(1);
});

test('staff in staff mode can see payouts but not request them', function () {
    [$landlord] = payoutLandlord();
    $account = verifiedAccount($landlord);
    $staff = StaffUser::factory()->withPermissions(['portal.access', 'portal.admin'])->create();

    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord));
    $this->actingAs($staff, 'staff')->get(route('payouts.index'))->assertOk()->assertSee('staff mode');
    $this->actingAs($staff, 'staff')->post(route('payouts.store'), ['payout_account_id' => $account->id, 'amount' => 10000, 'password' => 'x'])->assertForbidden();
});

// --- Approve & execute ----------------------------------------------------------

test('a mobile money payout needs two people, then is sent, confirmed and booked', function () {
    payoutBands();
    [$landlord, $admin] = payoutLandlord(500000);
    $account = verifiedAccount($landlord);
    $l = app(Liquidations::class)->request($landlord, $account, 200000, $admin);

    $approver = staffWith(['liquidations.approve', 'liquidations.execute']);
    $executor = staffWith(['liquidations.execute']);

    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.approve', $l->id))->assertSessionHas('success');
    expect($l->fresh()->status)->toBe('approved');

    // The approver cannot also send it.
    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.send', $l->id))->assertSessionHas('error');
    expect($l->fresh()->status)->toBe('approved');

    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.send', $l->id))->assertSessionHas('success');
    $l = $l->fresh();
    expect($l->status)->toBe('processing')->and($l->external_reference)->toBe($l->reference.'-1')->and($l->executed_by_staff_user_id)->toBe($executor->id)
        ->and(PaymentGatewayLog::where('liquidation_id', $l->id)->exists())->toBeTrue();

    // The scheduled reconcile confirms it.
    Liquidation::withoutGlobalScopes()->whereKey($l->id)->update(['last_checked_at' => now()->subMinutes(2)]);
    $this->artisan('payments:reconcile')->assertSuccessful();
    $l = $l->fresh();

    expect($l->status)->toBe('completed')->and($l->completed_at)->not->toBeNull()
        ->and(ledger()->landlordPayable($landlord->id))->toBe(300000.0)
        ->and(ledger()->balance('yo_pool'))->toBe(300000.0)          // 500,000 in − 198,500 sent − 1,500 Yo! charge
        ->and(ledger()->balance('payout_fee_income'))->toBe(1500.0)
        ->and(ledger()->balance('gateway_charges'))->toBe(1500.0)
        ->and(balanced())->toBeTrue();
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->subjectLine, 'sent'));

    // Reconciling again changes nothing.
    $this->artisan('payments:reconcile');
    expect(JournalEntry::where('source_type', 'liquidation')->count())->toBe(2);
});

test('a failed mobile money payout can be retried under a new reference, or rejected', function () {
    [$landlord, $admin] = payoutLandlord(500000);
    $account = verifiedAccount($landlord);
    $l = app(Liquidations::class)->request($landlord, $account, 100044, $admin); // the fake gateway fails amounts ending in 44
    $approver = staffWith(['liquidations.approve']);
    $executor = staffWith(['liquidations.execute']);
    app(Liquidations::class)->approve($l, $approver);

    app(Liquidations::class)->sendMobileMoney($l->fresh(), $executor);
    app(Liquidations::class)->refresh($l->fresh());
    expect($l->fresh()->status)->toBe('failed')->and(ledger()->landlordPayable($landlord->id))->toBe(500000.0);

    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.send', $l->id));
    expect($l->fresh()->attempts)->toBe(2)->and($l->fresh()->external_reference)->toBe($l->reference.'-2');
    app(Liquidations::class)->refresh($l->fresh());

    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.reject', $l->id), ['reason' => 'Number cannot receive money'])->assertSessionHas('success');
    expect($l->fresh()->status)->toBe('rejected')->and(app(Liquidations::class)->available($landlord->id))->toBe(500000.0);
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->subjectLine, 'not approved'));
});

test('a bank payout is completed by recording the EFT, and booked against the bank', function () {
    payoutBands();
    [$landlord, $admin] = payoutLandlord(800000);
    $account = verifiedAccount($landlord, 'bank');
    $l = app(Liquidations::class)->request($landlord, $account, 800000, $admin);
    $approver = staffWith(['liquidations.approve']);
    $executor = staffWith(['liquidations.execute']);

    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.approve', $l->id));
    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.complete-bank', $l->id), ['bank_reference' => 'EFT-7781', 'transfer_date' => today()->toDateString()])
        ->assertSessionHas('success');

    $l = $l->fresh();
    expect($l->status)->toBe('completed')->and($l->bank_reference)->toBe('EFT-7781')
        ->and(ledger()->landlordPayable($landlord->id))->toBe(0.0)
        ->and(ledger()->balance('bank'))->toBe(-800000.0)            // 795,000 sent + 5,000 bank charge
        ->and(ledger()->balance('bank_charges'))->toBe(5000.0)
        ->and(ledger()->balance('payout_fee_income'))->toBe(5000.0)
        ->and(balanced())->toBeTrue();
});

test('rejecting needs a reason and releases the money', function () {
    [$landlord, $admin] = payoutLandlord(300000);
    $l = app(Liquidations::class)->request($landlord, verifiedAccount($landlord), 300000, $admin);
    $approver = staffWith(['liquidations.approve']);

    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.reject', $l->id), [])->assertSessionHasErrors('reason');
    $this->actingAs($approver, 'staff')->post(route('staff.liquidations.reject', $l->id), ['reason' => 'Please confirm the number by phone first'])->assertSessionHas('success');

    expect($l->fresh()->status)->toBe('rejected')->and(app(Liquidations::class)->available($landlord->id))->toBe(300000.0);
});

test('staff payout screens render', function () {
    [$landlord, $admin] = payoutLandlord(300000);
    $l = app(Liquidations::class)->request($landlord, verifiedAccount($landlord), 100000, $admin);
    $staff = staffWith(['liquidations.view', 'payments.view']);

    $this->actingAs($staff, 'staff')->get(route('staff.liquidations.index'))->assertOk()->assertSee($l->reference);
    $this->actingAs($staff, 'staff')->get(route('staff.liquidations.show', $l->id))->assertOk()->assertSee('256772555666');
    $this->actingAs($staff, 'staff')->get(route('staff.payout-accounts.index', ['status' => 'verified']))->assertOk()->assertSee('Jane Owner');
});

// --- Yo! withdrawals --------------------------------------------------------------

function yoPayoutSetup(array $urls = ['https://yo-a.test/task.php']): array
{
    $fixtures = base_path('tests/Fixtures/payments');
    config([
        'payments.default' => 'yo',
        'payments.gateways.yo.api_username' => 'test-user',
        'payments.gateways.yo.api_password' => 'test-pass',
        'payments.gateways.yo.mode' => 'production',
        'payments.gateways.yo.urls.production' => $urls,
        'payments.gateways.yo.private_key' => "{$fixtures}/yo-test-private.pem",
    ]);

    [$landlord, $admin] = payoutLandlord(500000);
    $l = app(Liquidations::class)->request($landlord, verifiedAccount($landlord), 100000, $admin);
    app(Liquidations::class)->approve($l, staffWith(['liquidations.approve']));

    return [$l->fresh('landlord'), staffWith(['liquidations.execute'])];
}

test('Yo! withdrawals are signed with Primora\'s private key', function () {
    $sent = [];
    Http::fake(function (HttpRequest $request) use (&$sent) {
        $sent[] = $request->body();

        return Http::response('<?xml version="1.0"?><AutoCreate><Response><Status>OK</Status><StatusCode>1</StatusCode><TransactionStatus>PENDING</TransactionStatus><TransactionReference>YO-W-1</TransactionReference></Response></AutoCreate>');
    });
    [$l, $executor] = yoPayoutSetup();

    $l = app(Liquidations::class)->sendMobileMoney($l, $executor);

    expect($l->status)->toBe('processing')->and($l->gateway_reference)->toBe('YO-W-1');
    $xml = simplexml_load_string($sent[0])->Request;
    expect((string) $xml->Method)->toBe('acwithdrawfunds')->and((string) $xml->Account)->toBe('256772555666')->and((string) $xml->Amount)->toBe('100000');

    $signed = 'test-user'.$xml->Amount.$xml->Account.$xml->Narrative.$xml->ExternalReference.$xml->PublicKeyAuthenticationNonce;
    $public = openssl_pkey_get_public(file_get_contents(base_path('tests/Fixtures/payments/yo-test-public.pem')));
    expect(openssl_verify($signed, base64_decode((string) $xml->PublicKeyAuthenticationSignatureBase64), $public, OPENSSL_ALGO_SHA1))->toBe(1);

    // The signature is not stored in full in the log.
    expect(PaymentGatewayLog::where('liquidation_id', $l->id)->first()->request_body)->not->toContain((string) $xml->PublicKeyAuthenticationSignatureBase64);
});

test('a withdrawal that may have reached Yo! is never sent to the second server', function () {
    $hits = [];
    Http::fake(function (HttpRequest $request) use (&$hits) {
        $hits[] = $request->url();
        throw new ConnectionException('cURL error 28: Operation timed out after 45000 milliseconds');
    });
    [$l, $executor] = yoPayoutSetup(['https://yo-a.test/task.php', 'https://yo-b.test/task.php']);

    $l = app(Liquidations::class)->sendMobileMoney($l, $executor);

    expect($hits)->toBe(['https://yo-a.test/task.php'])      // not retried elsewhere
        ->and($l->status)->toBe('processing');                // left for the status check, never resent
});

test('a withdrawal that never left uses the second server', function () {
    $hits = [];
    Http::fake(function (HttpRequest $request) use (&$hits) {
        $hits[] = $request->url();
        if (str_contains($request->url(), 'yo-a')) {
            throw new ConnectionException('cURL error 7: Failed to connect to yo-a.test port 443');
        }

        return Http::response('<?xml version="1.0"?><AutoCreate><Response><Status>OK</Status><StatusCode>1</StatusCode><TransactionStatus>PENDING</TransactionStatus></Response></AutoCreate>');
    });
    [$l, $executor] = yoPayoutSetup(['https://yo-a.test/task.php', 'https://yo-b.test/task.php']);

    $l = app(Liquidations::class)->sendMobileMoney($l, $executor);

    expect($hits)->toBe(['https://yo-a.test/task.php', 'https://yo-b.test/task.php'])->and($l->status)->toBe('processing');
});

test('payout and books routes need their permissions', function () {
    [$landlord, $admin] = payoutLandlord(100000);
    $l = app(Liquidations::class)->request($landlord, verifiedAccount($landlord), 50000, $admin);
    $none = staffWith(['landlords.view']);

    foreach ([
        ['get', route('staff.liquidations.index')], ['get', route('staff.liquidations.show', $l->id)],
        ['post', route('staff.liquidations.approve', $l->id)], ['post', route('staff.liquidations.send', $l->id)],
        ['get', route('staff.books.index')], ['post', route('staff.books.expenses.store')],
    ] as [$method, $url]) {
        $this->actingAs($none, 'staff')->{$method}($url)->assertForbidden();
    }

    // Viewing doesn't allow approving.
    $viewer = staffWith(['liquidations.view', 'books.view']);
    $this->actingAs($viewer, 'staff')->post(route('staff.liquidations.approve', $l->id))->assertForbidden();
    $this->actingAs($viewer, 'staff')->post(route('staff.books.expenses.store'))->assertForbidden();
});

// --- Bank payouts through Yo! ---------------------------------------------------

function yoBankAccount(Landlord $landlord, ?string $identifier = 'YO-BANK-77'): PayoutAccount
{
    $account = verifiedAccount($landlord, 'bank');
    $account->forceFill(['yo_bank_identifier' => $identifier])->save();

    return $account;
}

test('staff register a verified bank account with Yo!, or enter its identifier', function () {
    [$landlord] = payoutLandlord();
    $a = verifiedAccount($landlord, 'bank');
    $b = verifiedAccount($landlord, 'bank');
    $staff = staffWith(['liquidations.approve']);

    $this->actingAs($staff, 'staff')->post(route('staff.payout-accounts.yo-register', $a->id), [
        'name' => 'Stanbic Bank Uganda Limited', 'address1' => 'Crested Towers', 'address2' => 'Plot 17 Hannington Road', 'city' => 'Kampala', 'country' => 'Uganda',
    ])->assertSessionHas('success');
    $a = $a->fresh();
    expect($a->yo_bank_identifier)->toBe('FAKE-BANK-'.$a->id)->and($a->yo_bank_info['name'])->toBe('Stanbic Bank Uganda Limited');

    // The next account at the same bank is prefilled.
    $this->actingAs($staff, 'staff')->get(route('staff.payout-accounts.index', ['status' => 'verified']))->assertOk()->assertSee('Filled in from an earlier registration');

    $this->actingAs($staff, 'staff')->post(route('staff.payout-accounts.yo-identifier', $b->id), ['yo_bank_identifier' => 'BANK-123'])->assertSessionHas('success');
    expect($b->fresh()->yo_bank_identifier)->toBe('BANK-123');

    // Viewers can't.
    $viewer = staffWith(['liquidations.view']);
    $this->actingAs($viewer, 'staff')->post(route('staff.payout-accounts.yo-identifier', $b->id), ['yo_bank_identifier' => 'X'])->assertForbidden();
});

test('a bank payout can go through Yo!, and is booked against the Yo! pool', function () {
    payoutBands();
    [$landlord, $admin] = payoutLandlord(800000);
    $l = app(Liquidations::class)->request($landlord, yoBankAccount($landlord), 400000, $admin);
    app(Liquidations::class)->approve($l, staffWith(['liquidations.approve']));
    $executor = staffWith(['liquidations.execute']);

    $this->actingAs($executor, 'staff')->get(route('staff.liquidations.show', $l->id))->assertOk()->assertSee('Through Yo! Payments')->assertSee('Direct transfer from Primora');

    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.send', $l->id), ['transfer_type' => 'EFT'])->assertSessionHas('success');
    $l = $l->fresh();
    expect($l->status)->toBe('processing')->and($l->route)->toBe('yo')->and($l->transfer_type)->toBe('EFT')
        ->and($l->destination['yo_bank_identifier'])->toBe('YO-BANK-77');

    app(Liquidations::class)->refresh($l);
    $l = $l->fresh();
    expect($l->status)->toBe('completed')
        ->and(ledger()->landlordPayable($landlord->id))->toBe(400000.0)
        ->and(ledger()->balance('yo_pool'))->toBe(400000.0)           // 800,000 − 395,000 sent − 5,000 Yo! charge
        ->and(ledger()->balance('bank'))->toBe(0.0)
        ->and(ledger()->balance('gateway_charges'))->toBe(5000.0)
        ->and(balanced())->toBeTrue();
});

test('Yo! is refused for bank accounts not registered with it; direct transfer still works', function () {
    [$landlord, $admin] = payoutLandlord(300000);
    $l = app(Liquidations::class)->request($landlord, yoBankAccount($landlord, null), 300000, $admin);
    app(Liquidations::class)->approve($l, staffWith(['liquidations.approve']));
    $executor = staffWith(['liquidations.execute']);

    $this->actingAs($executor, 'staff')->get(route('staff.liquidations.show', $l->id))->assertSee('registered with Yo! Payments yet', false);
    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.send', $l->id))->assertSessionHas('error');
    expect($l->fresh()->status)->toBe('approved');

    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.complete-bank', $l->id), ['bank_reference' => 'EFT-1', 'transfer_date' => today()->toDateString()]);
    expect($l->fresh()->status)->toBe('completed')->and($l->fresh()->route)->toBe('direct')->and(ledger()->balance('bank'))->toBe(-300000.0);
});

test('after a failed Yo! bank transfer the executor can switch to a direct transfer, but never while one is in flight', function () {
    [$landlord, $admin] = payoutLandlord(500000);
    $l = app(Liquidations::class)->request($landlord, yoBankAccount($landlord), 200044, $admin); // the fake gateway fails amounts ending in 44
    app(Liquidations::class)->approve($l, staffWith(['liquidations.approve']));
    $executor = staffWith(['liquidations.execute']);

    app(Liquidations::class)->sendViaYo($l->fresh(), $executor);
    expect($l->fresh()->status)->toBe('processing');
    // In flight: a direct transfer is refused.
    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.complete-bank', $l->id), ['bank_reference' => 'EFT-9', 'transfer_date' => today()->toDateString()])->assertSessionHas('error');

    app(Liquidations::class)->refresh($l->fresh());
    expect($l->fresh()->status)->toBe('failed');

    $this->actingAs($executor, 'staff')->post(route('staff.liquidations.complete-bank', $l->id), ['bank_reference' => 'EFT-9', 'transfer_date' => today()->toDateString()])->assertSessionHas('success');
    expect($l->fresh()->status)->toBe('completed')->and($l->fresh()->route)->toBe('direct')
        ->and(ledger()->balance('bank'))->toBe(-200044.0)->and(ledger()->balance('yo_pool'))->toBe(500000.0);
});

test('Yo! bank withdrawal and registration requests are signed as the spec says; CAPTURED completes', function () {
    $sent = [];
    $answers = [
        'accreateverifiedbankaccount' => '<Status>OK</Status><StatusCode>0</StatusCode><ApiBankIdentifier>YBI-555</ApiBankIdentifier>',
        'acwithdrawfundstobank' => '<Status>OK</Status><StatusCode>0</StatusCode><SettlementTransactionIdentifier>SET-1</SettlementTransactionIdentifier>',
        'accheckbanksettlementstatus' => '<Status>OK</Status><StatusCode>0</StatusCode><Amount>100000</Amount><CurrencyCode>UGX</CurrencyCode><TransactionStatus>CAPTURED</TransactionStatus><SettlementTransactionIdentifier>SET-1</SettlementTransactionIdentifier>',
    ];
    Http::fake(function (HttpRequest $request) use (&$sent, $answers) {
        $xml = simplexml_load_string($request->body());
        $sent[(string) $xml->Request->Method] = $xml->Request;

        return Http::response('<?xml version="1.0"?><AutoCreate><Response>'.$answers[(string) $xml->Request->Method].'</Response></AutoCreate>');
    });
    $public = openssl_pkey_get_public(file_get_contents(base_path('tests/Fixtures/payments/yo-test-public.pem')));
    $verify = fn ($data, $r) => openssl_verify($data, base64_decode((string) $r->PublicKeyAuthenticationSignatureBase64), $public, OPENSSL_ALGO_SHA1);

    [$l, $executor] = yoPayoutSetup();   // production mode, signing key configured
    $account = PayoutAccount::withoutGlobalScopes()->create(['landlord_id' => $l->landlord_id, 'type' => 'bank', 'account_name' => 'Ntinda Flats Ltd', 'bank_name' => 'Stanbic', 'account_number' => '9030012345678', 'status' => 'verified']);

    // Register (spec 16): nested BankInformation, signature over the fields in order.
    $identifier = app(\App\Payments\PaymentGatewayManager::class)->gateway()->registerBankAccount($account, [
        'name' => 'Stanbic Bank Uganda Limited', 'address1' => 'Crested Towers', 'address2' => 'Plot 17', 'city' => 'Kampala', 'country' => 'Uganda', 'swift' => 'SBICUGKX',
    ]);
    $r = $sent['accreateverifiedbankaccount'];
    expect($identifier)->toBe('YBI-555')->and((string) $r->BankInformation->Name)->toBe('Stanbic Bank Uganda Limited')
        ->and($verify('test-user'.'Ntinda Flats Ltd'.'9030012345678'.'Stanbic Bank Uganda Limited'.'Crested Towers'.'Plot 17'.'Kampala'.''.'Uganda'.'SBICUGKX'.''.''.''.$r->PublicKeyAuthenticationNonce, $r))->toBe(1);
    $account->update(['yo_bank_identifier' => $identifier]);

    // A bank payout to it (spec 5): signature over the fields in order; then status CAPTURED (spec 8).
    $bankPayout = Liquidation::withoutGlobalScopes()->create([
        'reference' => 'LIQ-261030-BANKYO', 'landlord_id' => $l->landlord_id, 'payout_account_id' => $account->id, 'method' => 'bank', 'destination' => $account->snapshot(),
        'amount' => 100000, 'fee' => 0, 'net_amount' => 100000, 'status' => 'approved', 'reviewed_by_staff_user_id' => $l->reviewed_by_staff_user_id,
    ]);
    app(Liquidations::class)->sendViaYo($bankPayout, $executor, 'RTGS');
    $r = $sent['acwithdrawfundstobank'];
    expect((string) $r->BankAccountIdentifier)->toBe('YBI-555')->and((string) $r->TransferTransactionType)->toBe('RTGS')->and((string) $r->CurrencyCode)->toBe('UGX')
        ->and($verify('test-user'.$r->Amount.'UGX'.'Ntinda Flats Ltd'.'9030012345678'.'YBI-555'.'RTGS'.$r->PrivateTransactionReference.$r->PublicKeyAuthenticationNonce, $r))->toBe(1)
        ->and($bankPayout->fresh()->gateway_reference)->toBe('SET-1');

    app(Liquidations::class)->refresh($bankPayout->fresh());
    expect((string) $sent['accheckbanksettlementstatus']->SettlementTransactionIdentifier)->toBe('SET-1')
        ->and($bankPayout->fresh()->status)->toBe('completed');
});

test('a cancelled Yo! bank settlement is a failure the executor can act on', function () {
    Http::fake(fn () => Http::response('<?xml version="1.0"?><AutoCreate><Response><Status>OK</Status><StatusCode>0</StatusCode><TransactionStatus>CANCELLED</TransactionStatus></Response></AutoCreate>'));
    [$l, $executor] = yoPayoutSetup();
    $account = PayoutAccount::withoutGlobalScopes()->create(['landlord_id' => $l->landlord_id, 'type' => 'bank', 'account_name' => 'X', 'bank_name' => 'B', 'account_number' => '1', 'status' => 'verified', 'yo_bank_identifier' => 'Y']);
    $p = Liquidation::withoutGlobalScopes()->create([
        'reference' => 'LIQ-261030-CANCEL', 'landlord_id' => $l->landlord_id, 'payout_account_id' => $account->id, 'method' => 'bank', 'destination' => $account->snapshot(),
        'amount' => 50000, 'fee' => 0, 'net_amount' => 50000, 'status' => 'approved', 'reviewed_by_staff_user_id' => $l->reviewed_by_staff_user_id,
    ]);

    app(Liquidations::class)->sendViaYo($p, $executor);

    expect($p->fresh()->status)->toBe('failed');
});
