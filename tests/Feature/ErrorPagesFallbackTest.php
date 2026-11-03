<?php

use Illuminate\Support\Facades\Route;

test('a 422 from abort() shows its message on the Primora error page', function () {
    Route::get('/_test/abort-422', fn () => abort(422, 'Only accounts awaiting verification can be verified.'));

    $this->get('/_test/abort-422')->assertStatus(422)
        ->assertSee('That couldn’t be done')
        ->assertSee('Only accounts awaiting verification can be verified.')
        ->assertDontSee('Oops! An Error Occurred');
});

test('other 4xx errors without their own page get the generic Primora page', function () {
    Route::get('/_test/abort-409', fn () => abort(409, 'internal detail'));

    $this->get('/_test/abort-409')->assertStatus(409)
        ->assertSee('409')
        ->assertSee('Please go back, refresh the page and try again.')
        ->assertDontSee('internal detail');
});
