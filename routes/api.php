<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The Primora mobile app (mobile/), version 1: landlords and their staff,
 * and tenants (under tenant/), signed in with a Sanctum token per device. See App\Http\Middleware\ApiLandlord
 * for the setup and subscription rules, answered with a JSON "code".
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    $Auth = \App\Http\Controllers\Api\V1\AuthController::class;
    Route::post('auth/login', [$Auth, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('auth/forgot-password', [$Auth, 'forgotPassword'])->middleware('throttle:5,1')->name('forgot-password');
    Route::post('auth/reset-password', [$Auth, 'resetPassword'])->middleware('throttle:10,1')->name('reset-password');

    // Tenants: sign in with a code by SMS or email (see Api\V1\Tenant\AuthController); see App\Http\Middleware\ApiTenant.
    Route::prefix('tenant')->name('tenant.')->group(function () {
        $TA = \App\Http\Controllers\Api\V1\Tenant\AuthController::class;
        Route::middleware('throttle:tenant-auth')->group(function () use ($TA) {
            Route::post('auth/lookup', [$TA, 'lookup'])->name('auth.lookup');
            Route::post('auth/choose', [$TA, 'choose'])->name('auth.choose');
            Route::post('auth/resend', [$TA, 'resend'])->name('auth.resend');
            Route::post('auth/verify', [$TA, 'verify'])->name('auth.verify');
            Route::post('auth/select', [$TA, 'select'])->name('auth.select');
        });

        Route::middleware(['auth:sanctum', 'api.tenant', 'throttle:tenant-api'])->group(function () use ($TA) {
            Route::get('auth/me', [$TA, 'me'])->name('auth.me');
            Route::post('auth/logout', [$TA, 'logout'])->name('auth.logout');
            Route::post('devices', [\App\Http\Controllers\Api\V1\DeviceController::class, 'store'])->name('devices.store');
            Route::delete('devices', [\App\Http\Controllers\Api\V1\DeviceController::class, 'destroy'])->name('devices.destroy');
            Route::get('accounts', [$TA, 'accounts'])->name('accounts');
            Route::post('accounts/switch', [$TA, 'switchAccount'])->name('accounts.switch');

            $Ac = \App\Http\Controllers\Api\V1\Tenant\AccountController::class;
            Route::get('dashboard', [$Ac, 'dashboard'])->name('dashboard');
            Route::get('invoices', [$Ac, 'invoices'])->name('invoices');
            Route::get('payments', [$Ac, 'payments'])->name('payments');
            Route::get('deposit', [$Ac, 'deposit'])->name('deposit');

            // The web portal's documents (ids, not bound models: see ApiTenant).
            $Doc = \App\Http\Controllers\TenantPortal\DocumentController::class;
            Route::get('invoices/{invoice}/pdf', [$Doc, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
            Route::get('payments/{payment}/receipt', [$Doc, 'receipt'])->whereNumber('payment')->name('payments.receipt');
            Route::get('leases/{lease}/agreement', [$Doc, 'agreement'])->whereNumber('lease')->name('leases.agreement');

            $Pay = \App\Http\Controllers\Api\V1\Tenant\PayController::class;
            Route::get('pay', [$Pay, 'options'])->name('pay.options');
            Route::get('pay/quote', [$Pay, 'quote'])->name('pay.quote');
            Route::post('pay', [$Pay, 'store'])->name('pay.store');
            Route::get('pay/{reference}', [$Pay, 'status'])->name('pay.status');

            $Is = \App\Http\Controllers\Api\V1\Tenant\IssueController::class;
            Route::get('issues', [$Is, 'index'])->name('issues.index');
            Route::get('issues/options', [$Is, 'options'])->name('issues.options');
            Route::post('issues', [$Is, 'store'])->name('issues.store');
            Route::get('issues/{issue}', [$Is, 'show'])->whereNumber('issue')->name('issues.show');
            Route::post('issues/{issue}/notes', [$Is, 'comment'])->whereNumber('issue')->name('issues.comment');
        });
    });

    Route::middleware(['auth:sanctum', 'api.landlord'])->group(function () use ($Auth) {
        Route::get('auth/me', [$Auth, 'me'])->name('auth.me');
        Route::post('auth/logout', [$Auth, 'logout'])->name('auth.logout');
        Route::post('auth/devices', [\App\Http\Controllers\Api\V1\DeviceController::class, 'store'])->middleware('throttle:20,1')->name('auth.devices.store');
        Route::delete('auth/devices', [\App\Http\Controllers\Api\V1\DeviceController::class, 'destroy'])->name('auth.devices.destroy');
        Route::post('auth/change-password', [$Auth, 'changePassword'])->name('auth.change-password');
        Route::post('auth/accept-terms', [$Auth, 'acceptTerms'])->name('auth.accept-terms');
        Route::post('auth/resend-verification', [$Auth, 'resendVerification'])->middleware('throttle:3,1')->name('auth.resend-verification');

        Route::get('dashboard', \App\Http\Controllers\Api\V1\DashboardController::class)->name('dashboard');

        $T = \App\Http\Controllers\Api\V1\TenantController::class;
        Route::get('tenants', [$T, 'index'])->name('tenants.index');
        Route::post('tenants', [$T, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [$T, 'show'])->whereNumber('tenant')->name('tenants.show');
        Route::post('tenants/{tenant}', [$T, 'update'])->whereNumber('tenant')->name('tenants.update'); // POST: photos are multipart

        $E = \App\Http\Controllers\Api\V1\EstateController::class;
        Route::get('estates', [$E, 'index'])->name('estates.index');
        Route::get('estates/places', [$E, 'places'])->name('estates.places');
        Route::post('estates', [$E, 'store'])->name('estates.store');
        Route::get('estates/{estate}', [$E, 'show'])->whereNumber('estate')->name('estates.show');
        Route::post('estates/{estate}', [$E, 'update'])->whereNumber('estate')->name('estates.update'); // POST: photos are multipart

        $U = \App\Http\Controllers\Api\V1\UnitController::class;
        Route::get('units', [$U, 'index'])->name('units.index');
        Route::post('units', [$U, 'store'])->name('units.store');
        Route::get('units/{unit}', [$U, 'show'])->whereNumber('unit')->name('units.show');
        Route::post('units/{unit}', [$U, 'update'])->whereNumber('unit')->name('units.update');

        $L = \App\Http\Controllers\Api\V1\LeaseController::class;
        Route::get('leases', [$L, 'index'])->name('leases.index');
        Route::post('leases', [$L, 'store'])->name('leases.store');
        Route::get('leases/{lease}', [$L, 'show'])->whereNumber('lease')->name('leases.show');
        Route::post('leases/{lease}', [$L, 'update'])->whereNumber('lease')->name('leases.update');

        // Mobile money payments the landlord started (SMS bundles, later subscriptions).
        Route::get('payments/status/{reference}', \App\Http\Controllers\Api\V1\PaymentStatusController::class)->middleware('throttle:60,1')->name('payments.status');

        // Admins only, as on the web.
        Route::middleware('api.admin')->group(function () {
            $Su = \App\Http\Controllers\Api\V1\SubscriptionController::class;
            Route::get('subscription', [$Su, 'show'])->name('subscription.show');
            Route::post('subscription/pay', [$Su, 'pay'])->middleware('throttle:10,1')->name('subscription.pay');
            Route::post('subscription/code', [$Su, 'code'])->middleware('throttle:10,1')->name('subscription.code');

            $S = \App\Http\Controllers\Api\V1\SmsController::class;
            Route::get('sms', [$S, 'overview'])->name('sms.overview');
            Route::get('sms/logs', [$S, 'logs'])->name('sms.logs');
            Route::post('sms/logs/resend-failed', [$S, 'resendFailed'])->middleware('throttle:5,1')->name('sms.resend-failed');
            Route::post('sms/logs/{smsLog}/resend', [$S, 'resend'])->whereNumber('smsLog')->middleware('throttle:30,1')->name('sms.resend');
            Route::get('sms/broadcasts', [$S, 'broadcasts'])->name('sms.broadcasts');
            Route::post('sms/broadcasts/preview', [$S, 'preview'])->middleware('throttle:60,1')->name('sms.broadcasts.preview');
            Route::post('sms/broadcasts', [$S, 'broadcastStore'])->middleware('throttle:10,1')->name('sms.broadcasts.store');
            Route::get('sms/broadcasts/{smsBroadcast}', [$S, 'broadcastShow'])->whereNumber('smsBroadcast')->name('sms.broadcasts.show');
            Route::post('sms/buy', [$S, 'buy'])->middleware('throttle:10,1')->name('sms.buy');

            $Po = \App\Http\Controllers\Api\V1\PayoutController::class;
            Route::get('payouts', [$Po, 'index'])->name('payouts.index');
            Route::post('payouts/quote', [$Po, 'quote'])->middleware('throttle:30,1')->name('payouts.quote');
            Route::post('payouts/request', [$Po, 'request'])->middleware('throttle:10,1')->name('payouts.request');
            Route::post('payouts/request/confirm', [$Po, 'confirmRequest'])->middleware('throttle:10,1')->name('payouts.request.confirm');
            Route::post('payouts/request/resend', [$Po, 'resendRequestCode'])->middleware('throttle:5,1')->name('payouts.request.resend');
            Route::delete('payouts/request', [$Po, 'cancelPendingRequest'])->name('payouts.request.cancel');
            Route::post('payouts/{liquidation}/cancel', [$Po, 'cancel'])->whereNumber('liquidation')->name('payouts.cancel');
            Route::post('payouts/accounts', [$Po, 'storeAccount'])->middleware('throttle:10,1')->name('payouts.accounts.store');
            Route::post('payouts/accounts/confirm', [$Po, 'confirmAccount'])->middleware('throttle:10,1')->name('payouts.accounts.confirm');
            Route::post('payouts/accounts/resend', [$Po, 'resendAccountCode'])->middleware('throttle:5,1')->name('payouts.accounts.resend');
            Route::delete('payouts/accounts/pending', [$Po, 'cancelPendingAccount'])->name('payouts.accounts.cancel');
            Route::delete('payouts/accounts/{payoutAccount}', [$Po, 'destroyAccount'])->whereNumber('payoutAccount')->name('payouts.accounts.destroy');

            $B = \App\Http\Controllers\Api\V1\BooksController::class;
            Route::middleware('api.feature:books')->group(function () use ($B) {
                Route::get('books', [$B, 'summary'])->name('books.summary');
                Route::get('books/pack', [$B, 'pack'])->middleware('throttle:20,1')->name('books.pack');
                Route::get('books/reports/{report}', [$B, 'report'])->where('report', '[a-z-]+')->name('books.report');
            });
        });

        $X = \App\Http\Controllers\Api\V1\ExpenseController::class;
        Route::get('expenses', [$X, 'index'])->name('expenses.index');
        Route::get('expenses/options', [$X, 'options'])->name('expenses.options');
        Route::post('expenses', [$X, 'store'])->name('expenses.store');
        Route::get('expenses/{expense}', [$X, 'show'])->whereNumber('expense')->name('expenses.show');
        Route::put('expenses/{expense}', [$X, 'update'])->whereNumber('expense')->name('expenses.update');
        Route::delete('expenses/{expense}', [$X, 'destroy'])->whereNumber('expense')->name('expenses.destroy');

        $Is = \App\Http\Controllers\Api\V1\IssueController::class;
        Route::get('issues', [$Is, 'index'])->name('issues.index');
        Route::get('issues/{issue}', [$Is, 'show'])->whereNumber('issue')->name('issues.show');
        Route::post('issues/{issue}/respond', [$Is, 'respond'])->whereNumber('issue')->name('issues.respond');

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
