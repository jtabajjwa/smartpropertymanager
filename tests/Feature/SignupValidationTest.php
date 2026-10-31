<?php

use App\Models\Landlord;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => Notification::fake());

function signupData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Kampala Properties Ltd.',
        'name' => 'Grace Namata',
        'email' => 'grace@kampalaproperties.test',
        'phone' => '+256772123456',
        'password' => 'Secret-1234',
        'password_confirmation' => 'Secret-1234',
    ], $overrides);
}

test('a valid sign-up creates the landlord', function () {
    $this->post(route('signup'), signupData())->assertSessionHasNoErrors()->assertRedirect();

    expect(Landlord::where('name', 'Kampala Properties Ltd.')->value('contact_phone'))->toBe('+256772123456');
});

test('the company name needs letters, and only business punctuation', function (string $name, bool $ok) {
    $response = $this->post(route('signup'), signupData(['company_name' => $name]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('company_name');
})->with([
    'digits only' => ['12345', false],
    'symbols only' => ['!!!', false],
    'odd characters' => ['Homes <script>', false],
    'with numbers' => ['Block 7 Apartments', true],
    'with punctuation' => ["A & B Homes (U) Ltd.", true],
]);

test('a person\'s name is letters only', function (string $name, bool $ok) {
    $response = $this->post(route('signup'), signupData(['name' => $name]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('name');
})->with([
    'digits' => ['12345', false],
    'mixed' => ['Grace 2', false],
    'apostrophe and hyphen' => ["Nakato O'Brien-Kaaya", true],
]);

test('the email must be a real address', function () {
    $this->post(route('signup'), signupData(['email' => 'jtabajjwa']))->assertSessionHasErrors('email');
});

test('the phone is a Ugandan number, however it was typed', function (string $phone, ?string $stored) {
    $response = $this->post(route('signup'), signupData(['phone' => $phone]));

    if ($stored === null) {
        $response->assertSessionHasErrors('phone');
    } else {
        $response->assertSessionHasNoErrors();
        expect(Landlord::first()->contact_phone)->toBe($stored);
    }
})->with([
    'local' => ['0701583888', '+256701583888'],
    'with code' => ['+256 701 583888', '+256701583888'],
    'too short' => ['+25670158', null],
    'foreign' => ['+447700900123', null],
]);

test('passwords need a letter, a number and a symbol', function (string $password, bool $ok) {
    $response = $this->post(route('signup'), signupData(['password' => $password, 'password_confirmation' => $password]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
})->with([
    'too short' => ['Ab1!', false],
    'no symbol' => ['secret1234', false],
    'no number' => ['secret-pass', false],
    'no letter' => ['1234-5678', false],
    'good' => ['Rent-2026!', true],
]);

test('an expired session sends people back to the form with what they typed, not a bare 419 page', function () {
    Route::middleware('web')->post('/_test/expired', fn () => abort(419));

    $this->from('/signup')
        ->post('/_test/expired', ['company_name' => 'Kept Ltd', 'password' => 'Secret-1234'])
        ->assertRedirect('/signup')
        ->assertSessionHas('error')
        ->assertSessionHasInput('company_name', 'Kept Ltd')
        ->assertSessionMissing('_old_input.password');
});

test('error pages are branded', function () {
    $this->get('/no-such-page-here')->assertNotFound()->assertSee('Page not found')->assertSee('Go back');
});
