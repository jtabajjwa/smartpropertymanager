<?php

use App\Models\Landlord;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Support\Tours;
use Illuminate\Support\Facades\Blade;

afterEach(fn () => CurrentLandlord::clear());

function tourUser(): User
{
    return User::factory()->admin()->create(['landlord_id' => Landlord::factory()->create()->id]);
}

/** The tour's Alpine config as rendered on a page. */
function tourConfig(string $html): ?array
{
    if (!preg_match('/x-data="tour\((.*?)\)"/s', $html, $m)) {
        return null;
    }
    // @js renders JSON.parse('<the JSON, itself JSON-string-escaped>'): undo both.
    preg_match("/JSON\.parse\('(.*)'\)/s", html_entity_decode($m[1], ENT_QUOTES), $json);

    return json_decode(json_decode('"'.$json[1].'"'), true);
}

test('a new landlord user is offered each tour once', function () {
    $user = tourUser();
    $this->actingAs($user);

    $config = tourConfig(Blade::render('<x-tour id="dashboard" />'));

    expect($config['offer'])->toBeTrue()
        ->and($config['autostart'])->toBeFalse()
        ->and(collect($config['steps'])->pluck('element'))->toContain('company', 'nav-property', 'stats', 'user-menu');

    // Finishing, skipping or declining it posts here.
    $this->postJson(route('guide.dismiss', Tours::key('dashboard')))->assertNoContent();

    expect(tourConfig(Blade::render('<x-tour id="dashboard" />'))['offer'])->toBeFalse()
        ->and(tourConfig(Blade::render('<x-tour id="leases" />'))['offer'])->toBeTrue();
});

test('turning tours off stops every prompt, and showing tips again brings them back', function () {
    $user = tourUser();
    $this->actingAs($user);

    $this->postJson(route('guide.dismiss', Tours::OFF))->assertNoContent();
    expect(tourConfig(Blade::render('<x-tour id="payments" />'))['offer'])->toBeFalse();

    $this->post(route('guide.restore'))->assertRedirect(route('dashboard'));
    expect(tourConfig(Blade::render('<x-tour id="payments" />'))['offer'])->toBeTrue();
});

test('"Take a tour" starts the tour even after it was dismissed', function () {
    $user = tourUser();
    $user->dismissTip(Tours::OFF);
    $this->actingAs($user);

    $this->get('/?tour=1');
    request()->merge(['tour' => '1']);

    expect(tourConfig(Blade::render('<x-tour id="dashboard" />'))['autostart'])->toBeTrue();
});

test('staff in staff mode get no tours, and unknown tours render nothing', function () {
    $this->actingAs(StaffUser::factory()->create(), 'staff');
    expect(trim(Blade::render('<x-tour id="dashboard" />')))->toBe('');

    $this->actingAs(tourUser());
    expect(trim(Blade::render('<x-tour id="no-such-tour" />')))->toBe('');
});

test('company profile is only mentioned to admins', function () {
    $member = User::factory()->create(['landlord_id' => Landlord::factory()->create()->id, 'role' => 'user']);

    expect(Tours::get('dashboard', $member)['steps'][0]['text'])->not->toContain('Company Profile')
        ->and(Tours::get('dashboard', tourUser())['steps'][0]['text'])->toContain('Company Profile');
});

test('the list pages carry the tour and its anchors', function (string $route) {
    $user = tourUser();
    $this->actingAs($user);

    $html = $this->get(route($route))->assertOk()->getContent();

    expect($html)->toContain('x-data="tour(', 'data-tour="add"', 'data-tour="filters"', 'data-tour="export"');
})->with(['leases.index', 'invoices.index', 'payments.index']);
