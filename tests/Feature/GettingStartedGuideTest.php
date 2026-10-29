<?php

use App\Models\Audit;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\GettingStarted;
use Illuminate\Support\Facades\Blade;

afterEach(fn () => CurrentLandlord::clear());

function newLandlordAdmin(array $landlord = []): User
{
    $landlord = Landlord::factory()->create($landlord);

    return User::factory()->admin()->create(['landlord_id' => $landlord->id]);
}

/** The dashboard's guide as $user would see it (the dashboard itself needs MySQL, so it's rendered on its own). */
function renderGuide(User $user): ?string
{
    $guide = CurrentLandlord::runAs($user->landlord_id, fn () => app(GettingStarted::class)->forDashboard($user));

    return $guide ? Blade::render('<x-getting-started :guide="$guide" />', ['guide' => $guide]) : null;
}

test('a new landlord sees the getting-started guide with every step to do', function () {
    $admin = newLandlordAdmin(['address' => null, 'logo' => null]);

    expect(renderGuide($admin))
        ->toContain('Getting started', '0 of 6 done', 'Add your logo and address', e(route('estates.index', ['new' => 1])));
});

test('steps tick themselves off from what is recorded', function () {
    $admin = newLandlordAdmin(['address' => 'Plot 4, Ntinda', 'logo' => null]);

    CurrentLandlord::runAs($admin->landlord_id, fn () => Estate::create(['name' => 'Hilltop', 'district' => 'Kampala']));

    $guide = CurrentLandlord::runAs($admin->landlord_id, fn () => app(GettingStarted::class)->forDashboard($admin));
    $done = collect($guide['steps'])->where('done', true)->pluck('key')->all();

    expect($done)->toBe(['company', 'estate'])
        ->and($guide['next'])->toBe('unit')
        ->and($guide['complete'])->toBeFalse();
});

test('team members get the guide without the admin-only company step', function () {
    $admin = newLandlordAdmin();
    $member = User::factory()->create(['landlord_id' => $admin->landlord_id]);

    expect(renderGuide($member))
        ->toContain('0 of 5 done')
        ->not->toContain('Add your logo and address');
});

test('hiding the guide is remembered, without an audit entry, and it can be brought back', function () {
    $admin = newLandlordAdmin();
    $audits = Audit::count();

    $this->actingAs($admin)->postJson(route('guide.dismiss', GettingStarted::KEY))->assertNoContent();

    expect($admin->fresh()->hasDismissedTip(GettingStarted::KEY))->toBeTrue()
        ->and(Audit::count())->toBe($audits);
    expect(renderGuide($admin->fresh()))->toBeNull();

    $this->post(route('guide.restore'))->assertRedirect(route('dashboard'));
    expect(renderGuide($admin->fresh()))->toContain('Getting started');
});

test('page tips show until closed, even on a read-only account', function () {
    $admin = newLandlordAdmin(['subscription_status' => 'trialing', 'current_period_end' => today()->subDays(30)]);

    $this->actingAs($admin)->get(route('estates.index'))->assertOk()->assertSee('Start with your properties');

    $this->postJson(route('guide.dismiss', 'tip-estates'))->assertNoContent();

    $this->get(route('estates.index'))->assertOk()->assertDontSee('Start with your properties');
    $this->get(route('units.index'))->assertOk()->assertSee('Every unit has its own ID');
});

test('tip keys are limited to simple names', function () {
    $this->actingAs(newLandlordAdmin())
        ->post('/guide/tips/'.str_repeat('a', 41).'/dismiss')
        ->assertNotFound();
});
