<?php

use App\Models\Audit;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PlanTier;
use App\Models\StaffUser;
use App\Models\User;

/** A tiered plan (1–6 units: 15k/45k/180k; 7–12: 20k/60k/240k). */
function staffSubsPlan(): Plan
{
    $plan = Plan::factory()->tiered()->create(['name' => 'Premium Plan']);
    PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 1, 'max_units' => 6, 'monthly_price' => 15000, 'quarterly_price' => 45000, 'annual_price' => 180000]);
    PlanTier::factory()->create(['plan_id' => $plan->id, 'min_units' => 7, 'max_units' => 12, 'monthly_price' => 20000, 'quarterly_price' => 60000, 'annual_price' => 240000]);

    return $plan;
}

// --- Overview ---

test('staff see every landlord\'s subscription with its state', function () {
    $plan = staffSubsPlan();
    Landlord::factory()->create(['name' => 'Active Co', 'subscription_status' => 'active', 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'current_period_end' => today()->addDays(20)]);
    Landlord::factory()->create(['name' => 'Soon Co', 'subscription_status' => 'active', 'current_period_end' => today()->addDays(3)]);
    Landlord::factory()->create(['name' => 'Lapsed Co', 'subscription_status' => 'active', 'current_period_end' => today()->subDays(5)]);
    Landlord::factory()->create(['name' => 'Trial Co', 'subscription_status' => 'trialing']);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index'))
        ->assertOk()
        ->assertSee('Active Co')->assertSee('Soon Co')->assertSee('Lapsed Co')->assertSee('Trial Co')
        ->assertSee('Expiring soon')->assertSee('Expired')->assertSee('Free trial');
});

test('subscriptions can be filtered by state', function () {
    Landlord::factory()->create(['name' => 'Soon Co', 'subscription_status' => 'active', 'current_period_end' => today()->addDays(3)]);
    Landlord::factory()->create(['name' => 'Later Co', 'subscription_status' => 'active', 'current_period_end' => today()->addDays(60)]);
    Landlord::factory()->create(['name' => 'Lapsed Co', 'subscription_status' => 'active', 'current_period_end' => today()->subDay()]);
    Landlord::factory()->create(['name' => 'Gone Co', 'subscription_status' => 'cancelled']);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index', ['state' => 'expiring']))
        ->assertSee('Soon Co')->assertDontSee('Later Co')->assertDontSee('Lapsed Co');
    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index', ['state' => 'expired']))
        ->assertSee('Lapsed Co')->assertDontSee('Soon Co');
    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index', ['state' => 'active']))
        ->assertSee('Soon Co')->assertSee('Later Co')->assertDontSee('Lapsed Co')->assertDontSee('Gone Co');
});

test('monthly recurring revenue converts each active subscription to a monthly figure', function () {
    // The migrations' default landlord (no plan) is already here: count relative to it.
    $baseActive = Landlord::all()->filter(fn ($l) => in_array($l->subscriptionState(), ['active', 'expiring']))->count();
    $baseExpired = Landlord::all()->filter(fn ($l) => in_array($l->subscriptionState(), ['expired', 'past_due', 'trial_ended']))->count();
    $plan = staffSubsPlan();
    // 3 units, quarterly → 45,000 / 3 = 15,000 per month
    Landlord::factory()->create(['subscription_status' => 'active', 'plan_id' => $plan->id, 'billing_cycle' => 'quarterly', 'current_period_end' => today()->addMonth()]);
    // expired → not counted
    Landlord::factory()->create(['subscription_status' => 'active', 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'current_period_end' => today()->subDay()]);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.index'))
        ->assertViewHas('stats', fn ($s) => $s['mrr'] == 15000 && $s['active'] === $baseActive + 1 && $s['expired'] === $baseExpired + 1);
});

// --- Adjusting ---

test('staff can adjust a subscription, and the change is kept with who and why', function () {
    $plan = staffSubsPlan();
    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing']);
    $staff = StaffUser::factory()->create(['name' => 'Grace Support']);

    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.update', $landlord), [
        'subscription_status' => 'trialing',
        'current_period_end' => today()->addDays(14)->toDateString(),
        'plan_id' => $plan->id,
        'billing_cycle' => 'monthly',
        'reason' => '14-day trial extension agreed by phone',
    ])->assertSessionHas('success', 'Subscription updated.');

    expect($landlord->fresh()->current_period_end->toDateString())->toBe(today()->addDays(14)->toDateString());

    $audit = Audit::where('auditable_type', Landlord::class)->where('auditable_id', $landlord->id)->where('action', 'updated')->latest('id')->first();
    expect($audit->staff_user_id)->toBe($staff->id)
        ->and($audit->landlord_id)->toBe($landlord->id)
        ->and($audit->note)->toBe('Adjusted by staff: 14-day trial extension agreed by phone');

    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.show', $landlord))
        ->assertSee('Grace Support (Primora staff)')
        ->assertSee('14-day trial extension agreed by phone')
        ->assertSee('Paid until');
});

test('a reason is required to adjust a subscription', function () {
    $landlord = Landlord::factory()->create(['subscription_status' => 'trialing']);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->put(route('staff.subscriptions.update', $landlord), ['subscription_status' => 'cancelled', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($landlord->fresh()->subscription_status)->toBe('trialing');
});

// --- Offline payments ---

test('recording an offline payment extends the subscription and shows under payments', function () {
    $plan = staffSubsPlan();
    $landlord = Landlord::factory()->create(['subscription_status' => 'active', 'current_period_end' => today()->addDays(10)]);
    $staff = StaffUser::factory()->create(['name' => 'Grace Support']);

    $this->actingAs($staff, 'staff')->post(route('staff.subscriptions.payments.store', $landlord), [
        'plan_id' => $plan->id, 'cycle' => 'quarterly', 'amount' => 45000, 'method' => 'cash',
        'received_on' => today()->subDay()->toDateString(), 'reference' => 'RCPT-0042',
    ])->assertSessionHas('success');

    $landlord->refresh();
    expect($landlord->current_period_end->toDateString())->toBe(today()->addDays(10)->addMonthsNoOverflow(3)->toDateString())
        ->and($landlord->plan_id)->toBe($plan->id)
        ->and($landlord->billing_cycle)->toBe('quarterly');

    $tx = PaymentTransaction::firstOrFail();
    expect($tx->gateway)->toBe('manual')
        ->and($tx->status)->toBe('succeeded')
        ->and($tx->receipt_number)->toBe('RCPT-0042')
        ->and($tx->initiated_by_staff_user_id)->toBe($staff->id)
        ->and($tx->completed_at->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($tx->context['method'])->toBe('cash')
        ->and($tx->narrative)->toStartWith("PRIMORA:SUB L{$landlord->id} {$tx->reference} |")
        ->and($tx->narrative)->toContain('Offline: Cash ref RCPT-0042');

    // It counts as a subscription payment everywhere.
    $this->actingAs($staff, 'staff')->get(route('staff.payments.index'))->assertSee($tx->reference);
    $this->actingAs($staff, 'staff')->get(route('staff.payments.show', $tx))->assertSee('received offline (Cash)');
    $this->actingAs($staff, 'staff')->get(route('staff.subscriptions.show', $landlord))
        ->assertSee($tx->reference)
        ->assertSee('recorded by Grace Support')
        ->assertSee('Paid: '.$tx->reference, false);
});

test('offline payments cannot be dated in the future', function () {
    $plan = staffSubsPlan();
    $landlord = Landlord::factory()->create();
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.subscriptions.payments.store', $landlord), [
        'plan_id' => $plan->id, 'cycle' => 'monthly', 'amount' => 15000, 'method' => 'cash', 'received_on' => today()->addDay()->toDateString(),
    ])->assertSessionHasErrors('received_on');

    expect(PaymentTransaction::count())->toBe(0);
});

test('the landlord sees offline payments in their own payment history', function () {
    $plan = staffSubsPlan();
    $landlord = Landlord::factory()->create();
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $staff = StaffUser::factory()->create();

    $this->actingAs($staff, 'staff')->post(route('staff.subscriptions.payments.store', $landlord), [
        'plan_id' => $plan->id, 'cycle' => 'monthly', 'amount' => 15000, 'method' => 'bank_transfer', 'received_on' => today()->toDateString(),
    ]);

    $this->actingAs($admin)->get(route('subscription.show'))->assertSee('Bank transfer')->assertSee('Paid');
});

test('only staff can manage subscriptions', function () {
    $landlord = Landlord::factory()->create();
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);

    $this->get(route('staff.subscriptions.index'))->assertRedirect(route('staff.login'));
    $this->actingAs($admin)->put(route('staff.subscriptions.update', $landlord), ['subscription_status' => 'active', 'reason' => 'trying it on'])
        ->assertRedirect(route('staff.login'));
});
