<?php

use App\Models\Landlord;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

function termsSignupData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Terms Homes Ltd',
        'name' => 'Grace Namata',
        'email' => 'grace@termshomes.test',
        'phone' => '+256772123999',
        'password' => 'Secret-1234',
        'password_confirmation' => 'Secret-1234',
        'terms' => '1',
    ], $overrides);
}

test('signing up needs the terms ticked', function () {
    Notification::fake();

    $this->post(route('signup'), termsSignupData(['terms' => null]))->assertSessionHasErrors('terms');

    expect(Landlord::where('name', 'Terms Homes Ltd')->exists())->toBeFalse();
});

test('signing up records which terms were accepted, when and by whom', function () {
    Notification::fake();

    $this->withServerVariables(['REMOTE_ADDR' => '102.85.1.20'])
        ->post(route('signup'), termsSignupData())
        ->assertSessionHasNoErrors();

    $landlord = Landlord::where('name', 'Terms Homes Ltd')->firstOrFail();

    expect($landlord->terms_version)->toBe(config('legal.terms_version'))
        ->and($landlord->terms_accepted_at)->not->toBeNull()
        ->and($landlord->terms_accepted_by)->toBe(User::where('email', 'grace@termshomes.test')->value('id'))
        ->and($landlord->terms_accepted_ip)->toBe('102.85.1.20')
        ->and($landlord->hasAcceptedCurrentTerms())->toBeTrue();
});

test('the sign-up page links to the terms and the privacy policy', function () {
    $this->get(route('signup'))->assertOk()
        ->assertSee(config('legal.terms_url'), false)
        ->assertSee(config('legal.privacy_url'), false)
        ->assertSee('name="terms"', false);
});

test('a landlord admin who has not accepted the terms is asked to, once, then goes where they were headed', function () {
    $landlord = Landlord::factory()->termsNotAccepted()->create();
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    $this->actingAs($admin);

    $this->get(route('leases.index'))->assertRedirect(route('terms.show'));
    $this->get(route('terms.show'))->assertOk()->assertSee('Before you continue')->assertSee($landlord->name);

    $this->post(route('terms.accept'))->assertSessionHasErrors('terms');
    $this->post(route('terms.accept'), ['terms' => '1'])->assertRedirect(route('leases.index'));

    expect($landlord->fresh()->hasAcceptedCurrentTerms())->toBeTrue()
        ->and($landlord->fresh()->terms_accepted_by)->toBe($admin->id);
    $this->get(route('leases.index'))->assertOk();
});

test('changing the terms version asks every landlord admin to accept the update', function () {
    $admin = User::factory()->admin()->create(['landlord_id' => Landlord::factory()->create()->id]);
    $this->actingAs($admin);
    $this->get(route('leases.index'))->assertOk();

    config(['legal.terms_version' => '2027-01-01']);

    $this->get(route('leases.index'))->assertRedirect(route('terms.show'));
    $this->get(route('terms.show'))->assertSee('We’ve updated our terms');
});

test('team members are not stopped: only an admin can accept for the company', function () {
    $landlord = Landlord::factory()->termsNotAccepted()->create();
    $member = User::factory()->create(['landlord_id' => $landlord->id, 'role' => 'user']);

    $this->actingAs($member)->get(route('leases.index'))->assertOk();
    $this->get(route('terms.show'))->assertRedirect(route('dashboard'));
    $this->post(route('terms.accept'), ['terms' => '1']);

    expect($landlord->fresh()->terms_version)->toBeNull();
});
