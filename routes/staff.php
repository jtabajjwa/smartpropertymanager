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

        // Every route below requires a specific staff permission (staff.can:…; see
        // App\Support\StaffPermissions). Staff admins have them all.
        $L = \App\Http\Controllers\Staff\LandlordController::class;
        Route::get('landlords', [$L, 'index'])->middleware('staff.can:landlords.view')->name('landlords.index');
        Route::post('landlords', [$L, 'store'])->middleware('staff.can:landlords.create')->name('landlords.store');
        Route::put('landlords/{landlord}', [$L, 'update'])->middleware('staff.can:landlords.edit')->name('landlords.update');
        Route::patch('landlords/{landlord}/suspend', [$L, 'suspend'])->middleware('staff.can:landlords.suspend')->name('landlords.suspend');
        Route::patch('landlords/{landlord}/reactivate', [$L, 'reactivate'])->middleware('staff.can:landlords.suspend')->name('landlords.reactivate');

        // "Staff mode": open a landlord's full portal on their behalf, and leave it again.
        // (Leaving is always allowed; ResolveLandlord re-checks access on every portal request.)
        Route::post('landlords/{landlord}/portal', [\App\Http\Controllers\Staff\LandlordPortalController::class, 'enter'])->middleware('staff.can:portal.access')->name('landlords.portal');
        Route::post('portal/exit', [\App\Http\Controllers\Staff\LandlordPortalController::class, 'exit'])->name('portal.exit');

        // Acting on behalf of one landlord: everything here is scoped to {landlord}.
        // Child IDs ({location}, {estate}, {unit}) are resolved in the controllers, not bound.
        Route::middleware('staff.landlord-context')->prefix('landlords/{landlord}')->name('landlords.')->group(function () {
            $view = 'staff.can:properties.view';
            $manage = 'staff.can:properties.manage';

            Route::get('/', [\App\Http\Controllers\Staff\LandlordController::class, 'show'])->middleware($view)->name('show');

            Route::get('locations', [\App\Http\Controllers\Staff\LandlordLocationController::class, 'index'])->middleware($view)->name('locations.index');
            Route::post('locations', [\App\Http\Controllers\Staff\LandlordLocationController::class, 'store'])->middleware($manage)->name('locations.store');
            Route::put('locations/{location}', [\App\Http\Controllers\Staff\LandlordLocationController::class, 'update'])->middleware($manage)->name('locations.update');
            Route::delete('locations/{location}', [\App\Http\Controllers\Staff\LandlordLocationController::class, 'destroy'])->middleware($manage)->name('locations.destroy');

            Route::get('estates', [\App\Http\Controllers\Staff\LandlordEstateController::class, 'index'])->middleware($view)->name('estates.index');
            Route::post('estates', [\App\Http\Controllers\Staff\LandlordEstateController::class, 'store'])->middleware($manage)->name('estates.store');
            Route::put('estates/{estate}', [\App\Http\Controllers\Staff\LandlordEstateController::class, 'update'])->middleware($manage)->name('estates.update');
            Route::delete('estates/{estate}', [\App\Http\Controllers\Staff\LandlordEstateController::class, 'destroy'])->middleware($manage)->name('estates.destroy');

            Route::get('units', [\App\Http\Controllers\Staff\LandlordUnitController::class, 'index'])->middleware($view)->name('units.index');
            Route::post('units', [\App\Http\Controllers\Staff\LandlordUnitController::class, 'store'])->middleware($manage)->name('units.store');
            Route::put('units/{unit}', [\App\Http\Controllers\Staff\LandlordUnitController::class, 'update'])->middleware($manage)->name('units.update');
            Route::delete('units/{unit}', [\App\Http\Controllers\Staff\LandlordUnitController::class, 'destroy'])->middleware($manage)->name('units.destroy');
        });

        $P = \App\Http\Controllers\Staff\PlanController::class;
        Route::get('plans', [$P, 'index'])->middleware('staff.can:plans.view')->name('plans.index');
        Route::post('plans', [$P, 'store'])->middleware('staff.can:plans.manage')->name('plans.store');
        Route::put('plans/{plan}', [$P, 'update'])->middleware('staff.can:plans.manage')->name('plans.update');
        Route::delete('plans/{plan}', [$P, 'destroy'])->middleware('staff.can:plans.manage')->name('plans.destroy');

        // Every landlord's Primora subscription: overview, adjustments, offline payments.
        $S = \App\Http\Controllers\Staff\SubscriptionController::class;
        Route::get('subscriptions', [$S, 'index'])->middleware('staff.can:subscriptions.view')->name('subscriptions.index');
        Route::get('subscriptions/{landlord}', [$S, 'show'])->middleware('staff.can:subscriptions.view')->name('subscriptions.show');
        Route::put('subscriptions/{landlord}', [$S, 'update'])->middleware('staff.can:subscriptions.adjust')->name('subscriptions.update');
        Route::post('subscriptions/{landlord}/payments', [$S, 'recordPayment'])->middleware('staff.can:subscriptions.record_payment')->name('subscriptions.payments.store');

        // Collections through payment gateways (Yo! Payments), with full gateway logs.
        $Pay = \App\Http\Controllers\Staff\PaymentController::class;
        Route::get('payments', [$Pay, 'index'])->middleware('staff.can:payments.view')->name('payments.index');
        Route::get('payments/unmatched-callbacks', [$Pay, 'unmatched'])->middleware('staff.can:payments.view')->name('payments.unmatched');
        Route::get('payments/{payment:reference}', [$Pay, 'show'])->middleware('staff.can:payments.view')->name('payments.show');
        Route::post('payments/{payment:reference}/check', [$Pay, 'check'])->middleware(['staff.can:payments.check', 'throttle:20,1'])->name('payments.check');

        // SMS reselling: provider stock, bundles, landlord balances, offline sales.
        $Sms = \App\Http\Controllers\Staff\SmsController::class;
        Route::get('sms', [$Sms, 'index'])->middleware('staff.can:sms.view')->name('sms.index');
        Route::post('sms/purchases', [$Sms, 'storePurchase'])->middleware('staff.can:sms.purchases')->name('sms.purchases.store');
        Route::put('sms/purchases/{purchase}', [$Sms, 'updatePurchase'])->middleware('staff.can:sms.purchases')->name('sms.purchases.update');
        Route::delete('sms/purchases/{purchase}', [$Sms, 'destroyPurchase'])->middleware('staff.can:sms.purchases')->name('sms.purchases.destroy');
        Route::post('sms/bundles', [$Sms, 'storeBundle'])->middleware('staff.can:sms.manage')->name('sms.bundles.store');
        Route::put('sms/bundles/{bundle}', [$Sms, 'updateBundle'])->middleware('staff.can:sms.manage')->name('sms.bundles.update');
        Route::delete('sms/bundles/{bundle}', [$Sms, 'destroyBundle'])->middleware('staff.can:sms.manage')->name('sms.bundles.destroy');
        Route::put('sms/settings', [$Sms, 'updateSettings'])->middleware('staff.can:sms.manage')->name('sms.settings.update');
        Route::get('sms/landlords', [$Sms, 'landlords'])->middleware('staff.can:sms.view')->name('sms.landlords');
        Route::get('sms/landlords/{landlord}', [$Sms, 'landlord'])->middleware('staff.can:sms.view')->name('sms.landlord');
        Route::post('sms/landlords/{landlord}/adjust', [$Sms, 'adjust'])->middleware('staff.can:sms.credits')->name('sms.adjust');
        Route::post('sms/landlords/{landlord}/sales', [$Sms, 'recordSale'])->middleware('staff.can:sms.credits')->name('sms.sales.store');

        // Rent tenants pay by mobile money into the pool, owed to landlords until settled.
        $Col = \App\Http\Controllers\Staff\CollectionController::class;
        Route::get('collections', [$Col, 'index'])->middleware('staff.can:collections.view')->name('collections.index');
        Route::get('collections/landlords/{landlord}', [$Col, 'show'])->middleware('staff.can:collections.view')->name('collections.show');
        Route::post('collections/landlords/{landlord}/toggle', [$Col, 'toggle'])->middleware('staff.can:collections.manage')->name('collections.toggle');
        Route::post('collections/landlords/{landlord}/settlements', [$Col, 'storeSettlement'])->middleware('staff.can:collections.settle')->name('collections.settlements.store');
        Route::delete('collections/landlords/{landlord}/settlements/{settlement}', [$Col, 'destroySettlement'])->middleware('staff.can:collections.settle')->name('collections.settlements.destroy');
        Route::post('collections/tiers', [$Col, 'storeTier'])->middleware('staff.can:collections.manage')->name('collections.tiers.store');
        Route::put('collections/tiers/{tier}', [$Col, 'updateTier'])->middleware('staff.can:collections.manage')->name('collections.tiers.update');
        Route::delete('collections/tiers/{tier}', [$Col, 'destroyTier'])->middleware('staff.can:collections.manage')->name('collections.tiers.destroy');

        // Revenue from every stream (subscriptions, SMS sales).
        Route::get('revenue', [\App\Http\Controllers\Staff\RevenueController::class, 'index'])->middleware('staff.can:revenue.view')->name('revenue.index');

        // Staff accounts and their permissions: staff admins only (prevents anyone granting themselves rights).
        Route::middleware('staff.admin')->group(function () {
            Route::resource('staff-users', \App\Http\Controllers\Staff\StaffUserController::class)->only(['index', 'store', 'update']);
            Route::patch('staff-users/{staff_user}/toggle-active', [\App\Http\Controllers\Staff\StaffUserController::class, 'toggleActive'])->name('staff-users.toggle-active');
        });
    });
});
