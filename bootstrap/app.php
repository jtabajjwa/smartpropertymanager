<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(__DIR__.'/../routes/staff.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'check-active' => \App\Http\Middleware\CheckActive::class,
            'must-change-password' => \App\Http\Middleware\MustChangePassword::class,
            'admin' => \App\Http\Middleware\AdminOnly::class,
            'resolve-landlord' => \App\Http\Middleware\ResolveLandlord::class,
            'staff.check-active' => \App\Http\Middleware\StaffCheckActive::class,
            'staff.must-change-password' => \App\Http\Middleware\StaffMustChangePassword::class,
            'staff.admin' => \App\Http\Middleware\StaffAdminOnly::class,
            'guest.staff' => \App\Http\Middleware\RedirectIfStaffAuthenticated::class,
            'auth.staff' => \App\Http\Middleware\AuthenticateStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
