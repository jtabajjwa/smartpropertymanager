<?php

use App\Accounting\Ledger;
use App\Mail\NoticeMail;
use App\Models\BalanceCheck;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Payments\Contracts\BalanceGateway;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\GatewayNotification;
use App\Payments\GatewayResult;
use App\Payments\Gateways\YoPaymentsGateway;
use App\Services\CurrentLandlord;
use App\Services\Reconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Automatic reconciliation: the Yo! pool balance against the books (allowing
 * for payouts being sent), landlord funds covered, alerts, and payout checks.
 */

/** A gateway whose balance the test sets. */
class ReconTestGateway implements PaymentGateway, BalanceGateway
{
    public static float|\RuntimeException $balance = 0.0;

    public function __construct(protected array $config) {}

    public function name(): string { return 'recon'; }

    public function isConfigured(): bool { return true; }

    public function requestPayment(PaymentTransaction $t): GatewayResult { return GatewayResult::pending(); }

    public function checkStatus(PaymentTransaction $t): GatewayResult { return GatewayResult::pending(); }

    public function parseNotification(Request $r, string $type): GatewayNotification { throw new \LogicException(); }

    public function notificationResponse(GatewayNotification $n): string { return 'OK'; }

    public function accountBalance(): array
    {
        if (self::$balance instanceof \RuntimeException) {
            throw self::$balance;
        }

        return ['total' => self::$balance, 'wallets' => ['UGX-MTNMM' => self::$balance]];
    }
}

beforeEach(function () {
    config(['payments.gateways.recon' => ['driver' => ReconTestGateway::class], 'payments.balance_check.gateway' => 'recon']);
    Mail::fake();
});
afterEach(fn () => CurrentLandlord::clear());

/** Yo! pool 1,000,000 in the books: 900,000 owed to a landlord, 100,000 Primora's. */
function poolOf(): Landlord
{
    $landlord = Landlord::factory()->create();
    app(Ledger::class)->post(today(), 'Rent and capital', [
        ['account' => 'yo_pool', 'debit' => 1000000],
        ['account' => 'landlord_payable', 'credit' => 900000, 'landlord_id' => $landlord->id],
        ['account' => 'equity', 'credit' => 100000],
    ], 'manual');

    return $landlord;
}

function check(float|\RuntimeException $yo): BalanceCheck
{
    ReconTestGateway::$balance = $yo;

    return app(Reconciliation::class)->check();
}

test('Yo! matching the books, allowing for payouts being sent', function () {
    $landlord = poolOf();
    expect(check(1000000))->status->toBe('matched')->difference->toBe(0.0)->cover()->toBe(100000.0);

    // A payout Yo! is sending has left the pool but isn't in the books yet.
    CurrentLandlord::runAs($landlord->id, fn () => Liquidation::create([
        'reference' => 'LIQ-R1', 'landlord_id' => $landlord->id, 'method' => 'mobile_money', 'destination' => [], 'amount' => 200000, 'fee' => 2000,
        'net_amount' => 198000, 'status' => Liquidation::PROCESSING, 'gateway' => 'recon', 'sent_at' => now(),
    ]));
    expect(check(800000))->status->toBe('matched')->in_flight->toBe(200000.0)
        ->and(check(790000))->status->toBe('difference')->difference->toBe(-10000.0);

    Mail::assertNothingSent();
});

test('a difference is emailed once it lasts two checks, not repeated, and cleared with a note', function () {
    poolOf();
    $finance = StaffUser::factory()->withPermissions(['books.view', 'books.post'])->create();
    StaffUser::factory()->withPermissions(['books.view'])->create();

    check(1005000);
    Mail::assertNothingSent(); // one check: could be a collection we haven't heard about yet

    $second = check(1005000);
    expect($second->problems)->toHaveKey('difference')->and($second->alerted_at)->not->toBeNull();
    Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($finance->email) && str_contains($m->subjectLine, 'differs') && count($m->to) === 1);

    check(1005000);
    Mail::assertSentCount(1);

    check(1000000);
    Mail::assertSentCount(2);
    Mail::assertSent(NoticeMail::class, fn ($m) => str_contains($m->subjectLine, 'back in step'));
    check(1000000);
    Mail::assertSentCount(2);
});

test('a shortfall in landlord funds is reported straight away', function () {
    poolOf();
    StaffUser::factory()->withPermissions(['books.post'])->create();

    $c = check(850000); // Yo! has less than the 900,000 owed to landlords
    expect($c->problems)->toHaveKey('shortfall')->and($c->cover())->toBe(-50000.0);
    Mail::assertSent(NoticeMail::class, fn ($m) => str_contains($m->subjectLine, 'not fully covered'));
});

test('when Yo! cannot be reached the check fails, still checks cover from the books, and repeated failures are reported', function () {
    poolOf();
    StaffUser::factory()->withPermissions(['books.post'])->create();

    $c = check(new \RuntimeException('Could not reach Yo! Payments: timeout'));
    expect($c->status)->toBe('failed')->and($c->money_held)->toBe(1000000.0)->and($c->problems)->toBe([]);

    check(new \RuntimeException('down'));
    Mail::assertNothingSent();
    expect(check(new \RuntimeException('down'))->problems)->toHaveKey('failing');
    Mail::assertSent(NoticeMail::class, fn ($m) => str_contains($m->subjectLine, 'failing'));
});

test('the reconciliation page and Check now', function () {
    poolOf();
    $viewer = StaffUser::factory()->withPermissions(['books.view'])->create();
    ReconTestGateway::$balance = 1000000;

    $this->actingAs($viewer, 'staff')->get(route('staff.books.reconciliation'))->assertOk()->assertSee('No checks yet');
    $this->actingAs($viewer, 'staff')->post(route('staff.books.reconciliation.check'))->assertSessionHas('success', 'Checked: Yo! matches the books.');
    expect(BalanceCheck::sole()->checked_by_staff_user_id)->toBe($viewer->id);

    $this->actingAs($viewer, 'staff')->get(route('staff.books.reconciliation'))->assertOk()
        ->assertSee('Matches the books')->assertSee('UGX 1,000,000')->assertSee('UGX-MTNMM')->assertSee('Covered');

    $other = StaffUser::factory()->withPermissions(['landlords.view'])->create();
    $this->actingAs($other, 'staff')->get(route('staff.books.reconciliation'))->assertForbidden();
    $this->actingAs($other, 'staff')->post(route('staff.books.reconciliation.check'))->assertForbidden();
});

test('the Yo! balance leaves out airtime wallets', function () {
    Http::fake(['*' => Http::sequence()
        ->push('<?xml version="1.0" encoding="UTF-8"?><AutoCreate><Response><Status>OK</Status><StatusCode>0</StatusCode><Balance>'
            .'<Currency><Code>UGX-MTNMM</Code><Balance>700000</Balance></Currency>'
            .'<Currency><Code>UGX-OATMM</Code><Balance>300000.50</Balance></Currency>'
            .'<Currency><Code>UGX-MTNAT</Code><Balance>5000</Balance></Currency>'
            .'</Balance></Response></AutoCreate>')
        ->push('<?xml version="1.0"?><AutoCreate><Response><Status>ERROR</Status><StatusCode>-1</StatusCode><ErrorMessage>Not permitted</ErrorMessage></Response></AutoCreate>')]);

    $yo = new YoPaymentsGateway(config('payments.gateways.yo') + ['api_username' => 'u', 'api_password' => 'p']);
    expect($yo->accountBalance())->toBe(['total' => 1000000.5, 'wallets' => ['UGX-MTNMM' => 700000.0, 'UGX-OATMM' => 300000.5]]);
    Http::assertSent(fn ($r) => str_contains($r->body(), '<Method>acacctbalance</Method>'));

    expect(fn () => $yo->accountBalance())->toThrow(\RuntimeException::class, 'Not permitted');
});

test('payouts still being sent are checked automatically and post to the books when done', function () {
    $landlord = Landlord::factory()->create();
    $l = CurrentLandlord::runAs($landlord->id, fn () => Liquidation::create([
        'reference' => 'LIQ-R2', 'external_reference' => 'LIQ-R2-1', 'landlord_id' => $landlord->id, 'method' => 'mobile_money', 'destination' => ['msisdn' => '256772123456'],
        'amount' => 100000, 'fee' => 1000, 'net_amount' => 99000, 'status' => Liquidation::PROCESSING, 'gateway' => 'fake', 'sent_at' => now()->subMinutes(2),
    ]));

    $this->artisan('payouts:reconcile')->expectsOutputToContain('1 settled')->assertSuccessful();
    expect($l->fresh()->status)->toBe(Liquidation::COMPLETED)
        ->and(\App\Models\JournalEntry::where('source_type', 'liquidation')->where('source_id', $l->id)->exists())->toBeTrue();

    // Checked recently: left alone until the next round.
    $this->artisan('payouts:reconcile')->expectsOutputToContain('Checked 0 payout(s)');
});
