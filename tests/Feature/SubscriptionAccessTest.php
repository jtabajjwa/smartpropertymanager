<?php

use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\SmsLog;
use App\Models\StaffUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\SmsNotificationService;
use App\Services\SubscriptionAccess;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

// Settings are cached in memory, and tests here change them.
beforeEach(fn () => Settings::flush());
afterEach(fn () => CurrentLandlord::clear());

/** A landlord admin whose trial or plan ended $daysAgo days ago (negative = still running). */
function endedLandlord(int $daysAgo, string $status = 'trialing', array $extra = []): array
{
    $landlord = Landlord::factory()->create(['subscription_status' => $status, 'current_period_end' => today()->subDays($daysAgo)] + $extra);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    return [$landlord, $admin];
}

test('new trials end after the configured number of days', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $signup = fn (string $company, string $email) => $this->post(route('signup'), [
        'company_name' => $company, 'name' => 'Owner Person', 'email' => $email, 'phone' => '+2567720'.random_int(10000, 99999),
        'password' => 'Secret-1234', 'password_confirmation' => 'Secret-1234',
    ])->assertRedirect();

    $signup('Bugolobi Homes', 'owner@bugolobi.test');
    expect(Landlord::where('name', 'Bugolobi Homes')->first()->current_period_end->toDateString())->toBe(today()->addDays(14)->toDateString());

    auth()->logout();
    Settings::set(SubscriptionAccess::TRIAL_DAYS_KEY, 30);
    $signup('Naguru Flats', 'owner@naguru.test');
    expect(Landlord::where('name', 'Naguru Flats')->first()->current_period_end->toDateString())->toBe(today()->addDays(30)->toDateString());
});

test('access is full until the end date, then grace, then read-only', function () {
    expect(endedLandlord(-3)[0]->accessLevel())->toBe('full')
        ->and(endedLandlord(0)[0]->accessLevel())->toBe('full')
        ->and(endedLandlord(1)[0]->accessLevel())->toBe('grace')
        ->and(endedLandlord(7)[0]->accessLevel())->toBe('grace')
        ->and(endedLandlord(8)[0]->accessLevel())->toBe('read_only')
        ->and(endedLandlord(8, 'active')[0]->accessLevel())->toBe('read_only')       // a lapsed paid plan too
        ->and(endedLandlord(8, 'active', ['grace_ends_at' => today()->addDay()])[0]->accessLevel())->toBe('grace') // staff extended grace
        ->and(Landlord::factory()->create(['subscription_status' => 'active', 'current_period_end' => null])->accessLevel())->toBe('full');

    Settings::set(SubscriptionAccess::GRACE_DAYS_KEY, 0);
    expect(endedLandlord(1)[0]->accessLevel())->toBe('read_only');
});

test('a read-only account can look but not change anything, apart from paying and payouts', function () {
    [$landlord, $admin] = endedLandlord(10);

    $this->actingAs($admin)->get(route('units.index'))->assertOk()
        ->assertSee('Your account is read-only.')->assertSee('Choose a plan');
    $this->actingAs($admin)->get(route('expenses.export'))->assertOk();

    $this->actingAs($admin)->from(route('units.index'))->post(route('units.store'), ['name' => 'Z9'])
        ->assertRedirect(route('units.index'))->assertSessionHas('error', fn ($m) => str_contains($m, 'free trial has ended'));
    $this->actingAs($admin)->postJson(route('units.store'), ['name' => 'Z9'])->assertForbidden();
    expect(Unit::withoutGlobalScopes()->where('landlord_id', $landlord->id)->count())->toBe(0);

    // Paying for a plan, buying SMS and payouts aren't blocked (they fail validation here instead).
    $this->actingAs($admin)->post(route('subscription.pay'), [])->assertSessionMissing('error');
    $this->actingAs($admin)->post(route('payouts.accounts.store'), [])->assertSessionMissing('error');
});

test('during grace everything still works, with a warning', function () {
    [, $admin] = endedLandlord(2);

    $this->actingAs($admin)->get(route('units.index'))->assertOk()
        ->assertSee('Your free trial ended on')->assertSee(today()->addDays(5)->format('j M Y'));
    $this->actingAs($admin)->post(route('units.store'), [])->assertSessionHasErrors()->assertSessionMissing('error');
});

test('a trial ending within a week is announced', function () {
    [, $admin] = endedLandlord(-3);
    $this->actingAs($admin)->get(route('units.index'))->assertOk()->assertSee('Your free trial ends in 3 days');
});

test('staff working in a read-only account are not blocked', function () {
    [$landlord] = endedLandlord(10);
    $staff = StaffUser::factory()->create();
    $this->actingAs($staff, 'staff')->post(route('staff.landlords.portal', $landlord))->assertRedirect(route('dashboard'));

    $this->actingAs($staff, 'staff')->post(route('units.store'), [])->assertSessionHasErrors()->assertSessionMissing('error');
});

test('a read-only account sends no SMS, and the log says why', function () {
    [$landlord] = endedLandlord(10);
    $landlord->forceFill(['sms_balance' => 100])->save();
    CurrentLandlord::set($landlord->id);

    $send = new ReflectionMethod(SmsNotificationService::class, 'sendAndLog');
    $send->invoke(app(SmsNotificationService::class), '256772123456', 'Hello', null, 'invoice_created', 'Invoice', 1);

    $log = SmsLog::first();
    expect($log->status)->toBe('read_only')->and($landlord->fresh()->sms_balance)->toBe(100);
});

test('admins are emailed before and after the trial ends, and when the account goes read-only', function () {
    Mail::fake();
    foreach ([-7 => 'in7', -1 => 'in1', 1 => 'ended', 8 => 'readonly', -3 => 'quiet', 3 => 'quiet2'] as $daysAgo => $slug) {
        [, $admin] = endedLandlord($daysAgo, 'trialing', ['contact_email' => "{$slug}@landlord.test"]);
    }

    $this->artisan('subscriptions:reminders')->assertSuccessful();

    $subjects = [];
    Mail::assertSent(NoticeMail::class, function (NoticeMail $mail) use (&$subjects) {
        foreach ($mail->to as $to) {
            $subjects[$to['address']] = $mail->subjectLine;
        }

        return true;
    });
    expect($subjects)->toHaveKeys(['in7@landlord.test', 'in1@landlord.test', 'ended@landlord.test', 'readonly@landlord.test'])
        ->and($subjects)->not->toHaveKeys(['quiet@landlord.test', 'quiet2@landlord.test'])
        ->and($subjects['in7@landlord.test'])->toBe('Your free trial ends in 7 days')
        ->and($subjects['in1@landlord.test'])->toBe('Your free trial ends tomorrow')
        ->and($subjects['ended@landlord.test'])->toBe('Your free trial has ended')
        ->and($subjects['readonly@landlord.test'])->toBe('Your Primora account is now read-only');
});

test('staff can change the trial and grace lengths', function () {
    $staff = StaffUser::factory()->admin()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index'))->assertOk()->assertSee('Trial &amp; grace', false);
    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.settings.update'), ['trial_days' => 21, 'grace_days' => 3])->assertSessionHas('success');
    Settings::flush();

    expect(SubscriptionAccess::trialDays())->toBe(21)->and(SubscriptionAccess::graceDays())->toBe(3);
    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.settings.update'), ['trial_days' => 0, 'grace_days' => -1])->assertSessionHasErrors(['trial_days', 'grace_days']);
});
