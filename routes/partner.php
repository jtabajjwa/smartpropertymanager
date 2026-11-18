<?php

use App\Http\Controllers\Partner\PartnerAuthController;
use App\Http\Controllers\Partner\PortalController;
use Illuminate\Support\Facades\Route;

/*
 * The partner portal: marketing partners and agents who sign landlords up
 * for a share of their subscriptions (see App\Services\PartnerProgram).
 */
Route::prefix('partners')->name('partner.')->group(function () {
    Route::get('login', [PartnerAuthController::class, 'showLogin'])->name('login');
    Route::post('login', [PartnerAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('apply', [PartnerAuthController::class, 'showApply'])->name('apply');
    Route::post('apply', [PartnerAuthController::class, 'apply'])->middleware('throttle:5,1');
    Route::get('forgot-password', [PartnerAuthController::class, 'showForgot'])->name('forgot-password');
    Route::post('forgot-password', [PartnerAuthController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::get('reset-password', [PartnerAuthController::class, 'showReset'])->name('reset-password');
    Route::post('reset-password', [PartnerAuthController::class, 'reset'])->middleware('throttle:10,1');
    Route::get('agreement-terms', [PartnerAuthController::class, 'publicAgreement'])->name('agreement.public');

    Route::middleware('auth.partner')->group(function () {
        Route::post('logout', [PartnerAuthController::class, 'logout'])->name('logout');
        Route::get('password', [PartnerAuthController::class, 'showPassword'])->name('password');
        Route::post('password', [PartnerAuthController::class, 'updatePassword'])->name('password.update');
        Route::get('agreement', [PartnerAuthController::class, 'showAgreement'])->name('agreement');
        Route::post('agreement', [PartnerAuthController::class, 'acceptAgreement'])->name('agreement.accept');

        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('profile', [PortalController::class, 'profile'])->name('profile');
    });

    // Approved partners who have accepted the agreement.
    Route::middleware('auth.partner:ready')->group(function () {
        Route::get('landlords', [PortalController::class, 'landlords'])->name('landlords');
        Route::get('landlords/new', [PortalController::class, 'createLandlord'])->name('landlords.create');
        Route::post('landlords', [PortalController::class, 'storeLandlord'])->middleware('throttle:20,1')->name('landlords.store');
        Route::get('codes', [PortalController::class, 'codes'])->name('codes');
        Route::get('earnings', [\App\Http\Controllers\Partner\EarningsController::class, 'index'])->name('earnings');
        Route::get('earnings/statement', [\App\Http\Controllers\Partner\EarningsController::class, 'statement'])->middleware('throttle:20,1')->name('earnings.statement');
    });
});
