<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The Primora mobile app (mobile/), version 1: landlords and their staff,
 * signed in with a Sanctum token per device. See App\Http\Middleware\ApiLandlord
 * for the setup and subscription rules, answered with a JSON "code".
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    $Auth = \App\Http\Controllers\Api\V1\AuthController::class;
    Route::post('auth/login', [$Auth, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('auth/forgot-password', [$Auth, 'forgotPassword'])->middleware('throttle:5,1')->name('forgot-password');
    Route::post('auth/reset-password', [$Auth, 'resetPassword'])->middleware('throttle:10,1')->name('reset-password');

    Route::middleware(['auth:sanctum', 'api.landlord'])->group(function () use ($Auth) {
        Route::get('auth/me', [$Auth, 'me'])->name('auth.me');
        Route::post('auth/logout', [$Auth, 'logout'])->name('auth.logout');
        Route::post('auth/change-password', [$Auth, 'changePassword'])->name('auth.change-password');
        Route::post('auth/accept-terms', [$Auth, 'acceptTerms'])->name('auth.accept-terms');
        Route::post('auth/resend-verification', [$Auth, 'resendVerification'])->middleware('throttle:3,1')->name('auth.resend-verification');

        Route::get('dashboard', \App\Http\Controllers\Api\V1\DashboardController::class)->name('dashboard');

        $T = \App\Http\Controllers\Api\V1\TenantController::class;
        Route::get('tenants', [$T, 'index'])->name('tenants.index');
        Route::post('tenants', [$T, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [$T, 'show'])->whereNumber('tenant')->name('tenants.show');
        Route::post('tenants/{tenant}', [$T, 'update'])->whereNumber('tenant')->name('tenants.update'); // POST: photos are multipart

        $L = \App\Http\Controllers\Api\V1\LeaseController::class;
        Route::get('leases', [$L, 'index'])->name('leases.index');
        Route::get('leases/{lease}', [$L, 'show'])->whereNumber('lease')->name('leases.show');

        $I = \App\Http\Controllers\Api\V1\InvoiceController::class;
        Route::get('invoices', [$I, 'index'])->name('invoices.index');
        Route::post('invoices', [$I, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [$I, 'show'])->whereNumber('invoice')->name('invoices.show');

        $P = \App\Http\Controllers\Api\V1\PaymentController::class;
        Route::get('payments', [$P, 'index'])->name('payments.index');
        Route::post('payments', [$P, 'store'])->name('payments.store');
        Route::get('payments/{payment}', [$P, 'show'])->whereNumber('payment')->name('payments.show');
        Route::get('payments/{payment}/receipt', [$P, 'receipt'])->whereNumber('payment')->name('payments.receipt');
    });
});

// Marketing website (primoraproptech.com): published plans and prices.
// Server-to-server only; needs the shared X-Website-Key header.
Route::get('website/plans', \App\Http\Controllers\Api\WebsitePlansController::class)
    ->middleware(['throttle:60,1', \App\Http\Middleware\VerifyWebsiteKey::class])
    ->name('api.website.plans');

// Tenant WhatsApp bot (Meta WhatsApp Cloud API). GET verifies the webhook once;
// POST carries messages, signed with the app secret.
Route::get('whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'verify'])->middleware('throttle:30,1')->name('api.whatsapp.verify');
Route::post('whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('api.whatsapp.receive');

// Yo! Custom USSD app call-outs (tenants paying rent by USSD; see deploy/YO_USSD.md).
// Signed by Yo!; each step of the menu has its own URL.
Route::post('ussd/yo/{step}', \App\Http\Controllers\Api\YoUssdController::class)
    ->whereIn('step', ['unit', 'units', 'confirm'])
    ->middleware('throttle:600,1')
    ->name('api.ussd.yo');
