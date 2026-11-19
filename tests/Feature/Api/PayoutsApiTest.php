<?php

use App\Accounting\Postings;
use App\Mail\ActionCodeMail;
use App\Models\CollectionFeeTier;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\PayoutAccount;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Mail;

/*
 * Mobile API, landlord phase 1f: payouts (admins). Adding a payout account and
 * requesting a payout need the password, then the code sent by email / SMS,
 * exactly as on the web (PayoutSteps).
 */

beforeEach(function () {
    Mail::fake();
    CollectionFeeTier::create(['kind' => 'payout_mobile_money', 'min_amount' => 500, 'max_amount' => null, 'fixed_fee' => 1500]);
});
afterEach(fn () => CurrentLandlord::clear());

/** A landlord Primora collected UGX 500,000 of rent for, with an admin. */
function payoutApiLandlord(): array
{
    $l = apiLandlord();
    $l['landlord']->update(['collect_payments' => true]);
    $tx = PaymentTransaction::create([
        'reference' => 'RNT-'.fake()->unique()->bothify('######-??????'), 'purpose' => 'rent', 'landlord_id' => $l['landlord']->id, 'gateway' => 'fake',
        'amount' => 500000, 'fee_amount' => 0, 'fee_bearer' => 'landlord', 'landlord_amount' => 500000,
        'msisdn' => '256772123456', 'narrative' => 'x', 'status' => 'succeeded', 'completed_at' => now(), 'context' => ['tenant_name' => 'Tenant'],
    ]);
    app(Postings::class)->paymentSucceeded($tx);

    return $l;
}

function payoutApiCode(): string
{
    return Mail::sent(ActionCodeMail::class)->last()->code;
}

test('a payout account is added with the password and then the code, and starts unverified', function () {
    $l = payoutApiLandlord();
    $token = apiToken($this, $l['admin']);
    $details = ['type' => 'mobile_money', 'account_name' => 'Jane Owner', 'msisdn' => '0772555666'];

    $this->withToken($token)->postJson(route('api.v1.payouts.accounts.store'), $details + ['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->withToken($token)->postJson(route('api.v1.payouts.accounts.store'), $details + ['password' => 'Secret-pass-123'])->assertOk()
        ->assertJsonPath('pending.summary', fn ($s) => str_contains($s, 'MTN 0772 555 666'));
    $this->withToken($token)->getJson(route('api.v1.payouts.index'))->assertOk()->assertJsonPath('pending_account.summary', fn ($s) => str_contains($s, 'MTN 0772 555 666'));

    $code = payoutApiCode();
    $this->withToken($token)->postJson(route('api.v1.payouts.accounts.confirm'), ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(0);

    $this->withToken($token)->postJson(route('api.v1.payouts.accounts.confirm'), ['code' => $code])->assertCreated()->assertJsonPath('account.status', 'pending');
    $this->withToken($token)->postJson(route('api.v1.payouts.accounts.confirm'), ['code' => $code])->assertUnprocessable()->assertJsonValidationErrors('request');
    expect(PayoutAccount::withoutGlobalScopes()->count())->toBe(1);
});

test('a payout is quoted, requested with the password and the code, and can be cancelled before Primora acts', function () {
    $l = payoutApiLandlord();
    $account = CurrentLandlord::runAs($l['landlord']->id, fn () => PayoutAccount::create(['type' => 'mobile_money', 'account_name' => 'Jane Owner', 'msisdn' => '256772555666', 'status' => 'verified']));
    CurrentLandlord::clear();
    $token = apiToken($this, $l['admin']);

    $this->withToken($token)->getJson(route('api.v1.payouts.index'))->assertOk()->assertJsonPath('available', 500000)->assertJsonPath('accounts.0.usable', true);
    $this->withToken($token)->postJson(route('api.v1.payouts.quote'), ['payout_account_id' => $account->id, 'amount' => 200000])->assertOk()
        ->assertJsonPath('fee', 1500)->assertJsonPath('net', 198500);
    $this->withToken($token)->postJson(route('api.v1.payouts.quote'), ['payout_account_id' => $account->id, 'amount' => 900000])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->withToken($token)->postJson(route('api.v1.payouts.request'), ['payout_account_id' => $account->id, 'amount' => 200000, 'password' => 'Secret-pass-123'])->assertOk()
        ->assertJsonPath('pending.net', 198500);
    expect(Liquidation::withoutGlobalScopes()->count())->toBe(0);   // nothing until the code

    $payout = $this->withToken($token)->postJson(route('api.v1.payouts.request.confirm'), ['code' => payoutApiCode()])->assertCreated()
        ->assertJsonPath('payout.amount', 200000)->assertJsonPath('payout.can_cancel', true)->json('payout');

    $this->withToken($token)->getJson(route('api.v1.payouts.index'))->assertOk()->assertJsonPath('available', 300000)->assertJsonPath('pending_payout', null);
    $this->withToken($token)->postJson(route('api.v1.payouts.cancel', $payout['id']))->assertOk()->assertJsonPath('payout.status', Liquidation::CANCELLED);
});

test('payouts are for admins, and a pending step belongs to the user who started it', function () {
    $l = payoutApiLandlord();
    $staff = \App\Models\User::factory()->create(['landlord_id' => $l['landlord']->id, 'role' => 'user', 'password' => 'Secret-pass-123']);
    $this->withToken(apiToken($this, $staff))->getJson(route('api.v1.payouts.index'))->assertForbidden()->assertJsonPath('code', 'admin_only');

    $other = \App\Models\User::factory()->admin()->create(['landlord_id' => $l['landlord']->id, 'password' => 'Secret-pass-123']);
    $this->withToken(apiToken($this, $l['admin']))->postJson(route('api.v1.payouts.accounts.store'), ['type' => 'mobile_money', 'account_name' => 'Jane', 'msisdn' => '0772555666', 'password' => 'Secret-pass-123'])->assertOk();
    $this->withToken(apiToken($this, $other))->postJson(route('api.v1.payouts.accounts.confirm'), ['code' => payoutApiCode()])->assertUnprocessable()->assertJsonValidationErrors('request');
});
