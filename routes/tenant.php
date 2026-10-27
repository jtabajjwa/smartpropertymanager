<?php

use App\Http\Controllers\TenantPortal;
use Illuminate\Support\Facades\Route;

/*
 * Tenant portal. Tenants sign in with a one-time SMS code (no password),
 * and see only their own account with their landlord.
 */
Route::prefix('tenant')->name('tenant.')->group(function () {
    $A = TenantPortal\AuthController::class;
    Route::get('login', [$A, 'show'])->name('login');
    Route::post('login', [$A, 'lookup'])->middleware('throttle:tenant-auth');
    Route::get('login/choose', [$A, 'choose'])->name('login.choose');
    Route::post('login/choose', [$A, 'chosen'])->middleware('throttle:tenant-auth');
    Route::get('verify', [$A, 'showVerify'])->name('verify');
    Route::post('verify', [$A, 'verify'])->middleware('throttle:tenant-auth');
    Route::post('verify/resend', [$A, 'resend'])->middleware('throttle:tenant-auth')->name('verify.resend');
    Route::get('accounts', [$A, 'accounts'])->name('accounts');
    Route::post('accounts', [$A, 'selectAccount']);
    Route::post('logout', [$A, 'logout'])->name('logout');

    Route::middleware('auth.tenant')->group(function () {
        $D = TenantPortal\DashboardController::class;
        Route::get('/', [$D, 'index'])->name('dashboard');
        Route::get('invoices', [$D, 'invoices'])->name('invoices');
        Route::get('payments', [$D, 'payments'])->name('payments');

        $Doc = TenantPortal\DocumentController::class;
        Route::get('invoices/{invoice}/pdf', [$Doc, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
        Route::get('leases/{lease}/agreement', [$Doc, 'agreement'])->whereNumber('lease')->name('leases.agreement');

        $P = TenantPortal\PaymentController::class;
        Route::get('pay', [$P, 'create'])->name('pay');
        Route::post('pay', [$P, 'store'])->middleware('throttle:tenant-portal');
        Route::get('pay/{reference}', [$P, 'status'])->middleware('throttle:tenant-portal')->name('pay.status');

        $I = TenantPortal\IssueController::class;
        Route::get('issues', [$I, 'index'])->name('issues.index');
        Route::get('issues/new', [$I, 'create'])->name('issues.create');
        Route::post('issues', [$I, 'store'])->middleware('throttle:tenant-portal')->name('issues.store');
        Route::get('issues/{issue}', [$I, 'show'])->whereNumber('issue')->name('issues.show');
        Route::post('issues/{issue}/notes', [$I, 'comment'])->whereNumber('issue')->middleware('throttle:tenant-portal')->name('issues.comment');
    });
});
