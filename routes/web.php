<?php

use Illuminate\Support\Facades\Route;

// Guest-only routes (redirect to dashboard if already logged in)
Route::middleware('guest')->group(function () {
    Route::get('login', [\App\Http\Controllers\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [\App\Http\Controllers\AuthController::class, 'login']);

    Route::get('signup', [\App\Http\Controllers\SignupController::class, 'show'])->name('signup');
    Route::post('signup', [\App\Http\Controllers\SignupController::class, 'store']);

    Route::get('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'showForgotForm'])->name('forgot-password');
    Route::post('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');

    Route::get('reset-password', [\App\Http\Controllers\PasswordResetController::class, 'showResetForm'])->name('reset-password');
    Route::post('reset-password', [\App\Http\Controllers\PasswordResetController::class, 'reset']);
});

// Auth-only routes (no active/password checks — allows logout + change-password + email verification)
Route::middleware('auth')->group(function () {
    Route::post('logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');

    Route::get('change-password', [\App\Http\Controllers\ChangePasswordController::class, 'show'])->name('change-password');
    Route::post('change-password', [\App\Http\Controllers\ChangePasswordController::class, 'update']);

    Route::get('email/verify', [\App\Http\Controllers\EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [\App\Http\Controllers\EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [\App\Http\Controllers\EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// Payment gateway callbacks (Yo! IPN / failure notifications). No session or CSRF:
// the gateway's server calls these; contents are re-checked with the gateway.
Route::post('payments/callback/{gateway}/{type}', \App\Http\Controllers\PaymentCallbackController::class)
    ->middleware('throttle:120,1')
    ->name('payments.callback');

// Redirect root to dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Fully protected routes (auth + landlord scoping + check-active + email verified + must-change-password).
// Staff may also enter, but only in "staff mode" for one landlord (see StaffActingSession / ResolveLandlord).
Route::middleware(['auth:web,staff', 'resolve-landlord', 'check-active', 'verified', 'must-change-password'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    // Property
    Route::resource('locations', \App\Http\Controllers\LocationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('estates', \App\Http\Controllers\EstateController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('units', \App\Http\Controllers\UnitController::class)->only(['index', 'store', 'update', 'destroy']);

    // Tenancy
    Route::resource('tenants', \App\Http\Controllers\TenantController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('customers', \App\Http\Controllers\CustomerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('leases', \App\Http\Controllers\LeaseController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('lease-templates', \App\Http\Controllers\LeaseTemplateController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('lease-templates/standard', [\App\Http\Controllers\LeaseTemplateController::class, 'addStandard'])->name('lease-templates.standard');
    Route::get('leases/{lease}/agreement', [\App\Http\Controllers\LeaseController::class, 'generateAgreement'])->name('leases.agreement');

    // Finance — exports (must be before resource routes)
    Route::get('leases/export', [\App\Http\Controllers\LeaseController::class, 'export'])->name('leases.export');
    Route::get('invoices/export', [\App\Http\Controllers\InvoiceController::class, 'export'])->name('invoices.export');
    Route::get('expenses/export', [\App\Http\Controllers\ExpenseController::class, 'export'])->name('expenses.export');
    Route::get('payments/export', [\App\Http\Controllers\PaymentController::class, 'export'])->name('payments.export');
    Route::get('security-deposit-ledgers/export', [\App\Http\Controllers\SecurityDepositLedgerController::class, 'export'])->name('security-deposit-ledgers.export');

    // Finance
    Route::resource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store', 'update']);
    Route::patch('invoices/{invoice}/cancel', [\App\Http\Controllers\InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('invoices/{invoice}/write-off', [\App\Http\Controllers\InvoiceController::class, 'writeOff'])->name('invoices.write-off');
    Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/send-email', [\App\Http\Controllers\InvoiceController::class, 'sendEmail'])->name('invoices.send-email');
    Route::resource('payments', \App\Http\Controllers\PaymentController::class)->only(['index', 'store']);
    Route::patch('payments/{payment}/cancel', [\App\Http\Controllers\PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::resource('payment-allocations', \App\Http\Controllers\PaymentAllocationController::class)->only(['index', 'store']);
    Route::resource('security-deposit-ledgers', \App\Http\Controllers\SecurityDepositLedgerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('leases/{lease}/deposit-balance', function (\App\Models\Lease $lease) {
        return response()->json(['balance' => $lease->securityDepositBalance()]);
    })->name('leases.deposit-balance');

    // Tenant issues (raised in the tenant portal)
    Route::get('issues', [\App\Http\Controllers\IssueController::class, 'index'])->name('issues.index');
    Route::get('issues/{issue}', [\App\Http\Controllers\IssueController::class, 'show'])->name('issues.show');
    Route::put('issues/{issue}', [\App\Http\Controllers\IssueController::class, 'update'])->name('issues.update');

    // Operations
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('expense-items', \App\Http\Controllers\ExpenseItemController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('utilities', \App\Http\Controllers\UtilityController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('vendors', \App\Http\Controllers\VendorController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('vendor-jobs', \App\Http\Controllers\VendorJobController::class)->only(['index', 'store', 'update', 'destroy']);

    // Admin-only
    Route::middleware('admin')->group(function () {
        Route::get('audits', [\App\Http\Controllers\AuditController::class, 'index'])->name('audits.index');
        Route::get('sms-logs', [\App\Http\Controllers\SmsLogController::class, 'index'])->name('sms-logs.index');
        Route::get('users', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
        Route::post('users', [\App\Http\Controllers\UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [\App\Http\Controllers\UserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/toggle-active', [\App\Http\Controllers\UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::get('subscription', [\App\Http\Controllers\SubscriptionController::class, 'show'])->name('subscription.show');
        Route::post('subscription/pay', [\App\Http\Controllers\SubscriptionController::class, 'pay'])->middleware('throttle:10,1')->name('subscription.pay');
        Route::get('subscription/payments/{reference}', [\App\Http\Controllers\LandlordPaymentController::class, 'status'])->middleware('throttle:60,1')->name('subscription.payment-status');
        Route::get('sms-credits', [\App\Http\Controllers\SmsCreditController::class, 'show'])->name('sms-credits.show');
        Route::post('sms-credits/buy', [\App\Http\Controllers\SmsCreditController::class, 'buy'])->middleware('throttle:10,1')->name('sms-credits.buy');
        Route::get('sms-credits/payments/{reference}', [\App\Http\Controllers\LandlordPaymentController::class, 'status'])->middleware('throttle:60,1')->name('sms-credits.payment-status');
        Route::get('company-profile', [\App\Http\Controllers\CompanyProfileController::class, 'edit'])->name('company-profile.edit');
        Route::put('company-profile', [\App\Http\Controllers\CompanyProfileController::class, 'update'])->name('company-profile.update');
        Route::put('company-profile/collections', [\App\Http\Controllers\CompanyProfileController::class, 'updateCollections'])->name('company-profile.collections');
    });

    // Search API (for searchable dropdowns)
    Route::prefix('search')->name('search.')->group(function () {
        Route::get('locations', [\App\Http\Controllers\SearchController::class, 'locations'])->name('locations');
        Route::get('estates', [\App\Http\Controllers\SearchController::class, 'estates'])->name('estates');
        Route::get('units', [\App\Http\Controllers\SearchController::class, 'units'])->name('units');
        Route::get('tenants', [\App\Http\Controllers\SearchController::class, 'tenants'])->name('tenants');
        Route::get('customers', [\App\Http\Controllers\SearchController::class, 'customers'])->name('customers');
        Route::get('leases', [\App\Http\Controllers\SearchController::class, 'leases'])->name('leases');
        Route::get('utilities', [\App\Http\Controllers\SearchController::class, 'utilities'])->name('utilities');
        Route::get('expense-items', [\App\Http\Controllers\SearchController::class, 'expenseItems'])->name('expense-items');
        Route::get('vendors', [\App\Http\Controllers\SearchController::class, 'vendors'])->name('vendors');
        Route::get('expenses', [\App\Http\Controllers\SearchController::class, 'expenses'])->name('expenses');
        Route::get('payments', [\App\Http\Controllers\SearchController::class, 'payments'])->name('payments');
        Route::get('invoices', [\App\Http\Controllers\SearchController::class, 'invoices'])->name('invoices');
        Route::get('users', [\App\Http\Controllers\SearchController::class, 'users'])->name('users');
    });
});
