<?php

use Illuminate\Support\Facades\Route;

// Guest-only routes (redirect to dashboard if already logged in)
Route::middleware('guest')->group(function () {
    Route::get('login', [\App\Http\Controllers\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [\App\Http\Controllers\AuthController::class, 'login']);

    Route::get('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'showForgotForm'])->name('forgot-password');
    Route::post('forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');

    Route::get('reset-password', [\App\Http\Controllers\PasswordResetController::class, 'showResetForm'])->name('reset-password');
    Route::post('reset-password', [\App\Http\Controllers\PasswordResetController::class, 'reset']);
});

// Auth-only routes (no active/password checks — allows logout + change-password)
Route::middleware('auth')->group(function () {
    Route::post('logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');

    Route::get('change-password', [\App\Http\Controllers\ChangePasswordController::class, 'show'])->name('change-password');
    Route::post('change-password', [\App\Http\Controllers\ChangePasswordController::class, 'update']);
});

// Redirect root to dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Fully protected routes (auth + check-active + must-change-password)
Route::middleware(['auth', 'check-active', 'must-change-password'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    // Property
    Route::resource('locations', \App\Http\Controllers\LocationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('estates', \App\Http\Controllers\EstateController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('units', \App\Http\Controllers\UnitController::class)->only(['index', 'store', 'update', 'destroy']);

    // Tenancy
    Route::resource('tenants', \App\Http\Controllers\TenantController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('customers', \App\Http\Controllers\CustomerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('leases', \App\Http\Controllers\LeaseController::class)->only(['index', 'store', 'update', 'destroy']);

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
    Route::resource('payments', \App\Http\Controllers\PaymentController::class)->only(['index', 'store']);
    Route::patch('payments/{payment}/cancel', [\App\Http\Controllers\PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::resource('payment-allocations', \App\Http\Controllers\PaymentAllocationController::class)->only(['index', 'store']);
    Route::resource('security-deposit-ledgers', \App\Http\Controllers\SecurityDepositLedgerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('leases/{lease}/deposit-balance', function (\App\Models\Lease $lease) {
        return response()->json(['balance' => $lease->securityDepositBalance()]);
    })->name('leases.deposit-balance');

    // Operations
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('expense-items', \App\Http\Controllers\ExpenseItemController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('utilities', \App\Http\Controllers\UtilityController::class)->only(['index', 'store', 'update', 'destroy']);

    // Admin-only
    Route::middleware('admin')->group(function () {
        Route::get('audits', [\App\Http\Controllers\AuditController::class, 'index'])->name('audits.index');
        Route::get('sms-logs', [\App\Http\Controllers\SmsLogController::class, 'index'])->name('sms-logs.index');
        Route::get('users', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
        Route::post('users', [\App\Http\Controllers\UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [\App\Http\Controllers\UserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/toggle-active', [\App\Http\Controllers\UserController::class, 'toggleActive'])->name('users.toggle-active');
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
        Route::get('payments', [\App\Http\Controllers\SearchController::class, 'payments'])->name('payments');
        Route::get('invoices', [\App\Http\Controllers\SearchController::class, 'invoices'])->name('invoices');
        Route::get('users', [\App\Http\Controllers\SearchController::class, 'users'])->name('users');
    });
});
