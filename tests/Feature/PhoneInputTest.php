<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\CurrentLandlord;
use Illuminate\Support\Facades\Blade;

afterEach(fn () => CurrentLandlord::clear());

test('the phone picker defaults to Uganda', function () {
    $html = Blade::render('<x-phone-input name="phone" required />');

    expect($html)->toContain('UG +256')
        ->toContain('<option value="UG" selected>')
        ->toContain('name="phone"');
});

test('the phone picker opens a stored number on its own country', function () {
    $html = Blade::render('<x-phone-input name="phone" :value="$v" />', ['v' => '+254712345678']);

    expect($html)->toContain('KE +254')->toContain('<option value="KE" selected>');
});

test('forms accept a number for any listed country and refuse invalid ones', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('customers.store'), ['name' => 'Nairobi Walk-in', 'phone' => '+254712345678'])->assertSessionHasNoErrors();
    $this->post(route('customers.store'), ['name' => 'Local Walk-in', 'phone' => '0772123456'])->assertSessionHasNoErrors();
    $this->post(route('customers.store'), ['name' => 'Bad Number', 'phone' => '+2547123'])->assertSessionHasErrors('phone');

    expect(Customer::pluck('phone')->sort()->values()->all())->toBe(['+254712345678', '+256772123456']);
});
