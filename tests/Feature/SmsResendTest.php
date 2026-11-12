<?php

use App\Models\Landlord;
use App\Models\SmsBroadcast;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\SmsService;

/*
 * Landlords resend SMS that failed (provider error, no credit, read-only
 * account) from SMS Logs, one at a time or all at once. Each resend is a new
 * log row paid from SMS credit; the original can only be resent once.
 */

beforeEach(function () {
    $this->providerUp = true;
    $this->sent = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sent[] = [$phone, $message];

            return $this->providerUp;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

function resendSetup(int $credits = 10): array
{
    $landlord = Landlord::factory()->create(['sms_balance' => $credits]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    return [$landlord, $admin];
}

function failedSms(Landlord $landlord, string $status = 'failed', array $extra = []): SmsLog
{
    return CurrentLandlord::runAs($landlord->id, fn () => SmsLog::create($extra + [
        'phone' => '256772123456', 'message' => 'Dear Grace, invoice INV-00001 has been issued.', 'segments' => 1,
        'notification_type' => 'invoice_created', 'reference_type' => 'Invoice', 'reference_id' => 1, 'status' => $status, 'error' => 'API returned failure',
    ]));
}

test('a failed SMS is resent once, as a new log paid from credit', function () {
    [$landlord, $admin] = resendSetup(10);
    $log = failedSms($landlord);

    $this->actingAs($admin)->get(route('sms-logs.index'))->assertOk()->assertSee('Resend all failed (1)')->assertSee(route('sms-logs.resend', $log));

    $this->actingAs($admin)->post(route('sms-logs.resend', $log))->assertSessionHas('success');
    expect($this->sent)->toBe([['256772123456', 'Dear Grace, invoice INV-00001 has been issued.']]);

    $log->refresh();
    $new = SmsLog::withoutGlobalScopes()->find($log->resent_log_id);
    expect($log->resent_at)->not->toBeNull()
        ->and($new->status)->toBe('sent')->and($new->notification_type)->toBe('invoice_created')->and($new->reference_id)->toBe(1)
        ->and($landlord->fresh()->sms_balance)->toBe(9);

    // Not again.
    $this->actingAs($admin)->post(route('sms-logs.resend', $log))->assertSessionHas('error');
    expect($this->sent)->toHaveCount(1);
    $this->actingAs($admin)->get(route('sms-logs.index'))->assertOk()->assertSee('Resent · delivered to the network')->assertDontSee('Resend all failed');
});

test('sent SMS cannot be resent; a resend that fails again can be retried', function () {
    [$landlord, $admin] = resendSetup(10);
    $sent = failedSms($landlord, 'sent');
    $this->actingAs($admin)->post(route('sms-logs.resend', $sent))->assertSessionHas('error');

    $log = failedSms($landlord, 'no_credit');
    $this->providerUp = false;
    $this->actingAs($admin)->post(route('sms-logs.resend', $log))->assertSessionHas('error');
    $retry = SmsLog::withoutGlobalScopes()->find($log->fresh()->resent_log_id);
    expect($retry->status)->toBe('failed')->and($landlord->fresh()->sms_balance)->toBe(10); // credit returned

    $this->providerUp = true;
    $this->actingAs($admin)->post(route('sms-logs.resend', $retry))->assertSessionHas('success');
});

test('resend all: the last 30 days, needs enough credit, and keeps bulk SMS totals right', function () {
    [$landlord, $admin] = resendSetup(2);
    CurrentLandlord::set($landlord->id);
    $broadcast = SmsBroadcast::create(['message' => 'Hi', 'audience' => ['estates' => []], 'status' => 'completed', 'recipients_count' => 3, 'sent_count' => 1, 'failed_count' => 2, 'credits_used' => 1]);
    CurrentLandlord::clear();

    $a = failedSms($landlord, 'failed', ['notification_type' => 'bulk', 'reference_type' => 'SmsBroadcast', 'reference_id' => $broadcast->id]);
    $b = failedSms($landlord, 'no_credit', ['notification_type' => 'bulk', 'reference_type' => 'SmsBroadcast', 'reference_id' => $broadcast->id]);
    $c = failedSms($landlord, 'read_only');
    $old = failedSms($landlord);
    SmsLog::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);

    // Three to resend, two credits: refused, nothing sent.
    $this->actingAs($admin)->post(route('sms-logs.resend-failed'))->assertSessionHas('error');
    expect($this->sent)->toBeEmpty();

    \Illuminate\Support\Facades\DB::table('landlords')->where('id', $landlord->id)->update(['sms_balance' => 5]);
    $this->actingAs($admin)->post(route('sms-logs.resend-failed'))->assertSessionHas('success', '3 SMS resent.');

    expect($this->sent)->toHaveCount(3)
        ->and($a->fresh()->resent_at)->not->toBeNull()->and($c->fresh()->resent_at)->not->toBeNull()
        ->and($old->fresh()->resent_at)->toBeNull()
        ->and($landlord->fresh()->sms_balance)->toBe(2)
        ->and($broadcast->fresh()->only(['sent_count', 'failed_count', 'credits_used']))->toBe(['sent_count' => 3, 'failed_count' => 0, 'credits_used' => 3]);
});

test('read-only accounts cannot resend; landlords only resend their own SMS', function () {
    [$landlord, $admin] = resendSetup();
    [$other] = resendSetup();
    $theirs = failedSms($other);

    $this->actingAs($admin)->post(route('sms-logs.resend', $theirs))->assertNotFound();

    $mine = failedSms($landlord);
    \App\Support\Settings::flush();
    $landlord->update(['subscription_status' => 'trialing', 'current_period_end' => today()->subDays(30)]); // trial and grace long over
    expect($landlord->fresh()->isReadOnly())->toBeTrue();

    CurrentLandlord::clear();
    $this->actingAs($admin)->post(route('sms-logs.resend', $mine));
    expect($mine->fresh()->resent_at)->toBeNull()->and($this->sent)->toBeEmpty();
});
