<?php

use Illuminate\Support\Facades\Route;

Route::prefix('staff')->name('staff.')->group(function () {

    // Guest-only routes (redirect to dashboard if already logged in)
    Route::middleware('guest.staff')->group(function () {
        Route::get('login', [\App\Http\Controllers\Staff\StaffAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [\App\Http\Controllers\Staff\StaffAuthController::class, 'login']);

        Route::get('forgot-password', [\App\Http\Controllers\Staff\StaffPasswordResetController::class, 'showForgotForm'])->name('forgot-password');
        Route::post('forgot-password', [\App\Http\Controllers\Staff\StaffPasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');

        Route::get('reset-password', [\App\Http\Controllers\Staff\StaffPasswordResetController::class, 'showResetForm'])->name('reset-password');
        Route::post('reset-password', [\App\Http\Controllers\Staff\StaffPasswordResetController::class, 'reset']);
    });

    // Auth-only routes (no active/password checks — allows logout + change-password)
    Route::middleware('auth.staff')->group(function () {
        Route::post('logout', [\App\Http\Controllers\Staff\StaffAuthController::class, 'logout'])->name('logout');

        Route::get('change-password', [\App\Http\Controllers\Staff\StaffChangePasswordController::class, 'show'])->name('change-password');
        Route::post('change-password', [\App\Http\Controllers\Staff\StaffChangePasswordController::class, 'update']);
    });

    // Fully protected routes
    Route::middleware(['auth.staff', 'staff.check-active', 'staff.must-change-password'])->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\Staff\StaffDashboardController::class, 'index'])->name('dashboard');

        Route::resource('landlords', \App\Http\Controllers\Staff\LandlordController::class)->only(['index', 'store', 'update']);
        Route::patch('landlords/{landlord}/suspend', [\App\Http\Controllers\Staff\LandlordController::class, 'suspend'])->name('landlords.suspend');
        Route::patch('landlords/{landlord}/reactivate', [\App\Http\Controllers\Staff\LandlordController::class, 'reactivate'])->name('landlords.reactivate');

        Route::resource('plans', \App\Http\Controllers\Staff\PlanController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::middleware('staff.admin')->group(function () {
            Route::resource('staff-users', \App\Http\Controllers\Staff\StaffUserController::class)->only(['index', 'store', 'update']);
            Route::patch('staff-users/{staff_user}/toggle-active', [\App\Http\Controllers\Staff\StaffUserController::class, 'toggleActive'])->name('staff-users.toggle-active');
        });
    });
});
