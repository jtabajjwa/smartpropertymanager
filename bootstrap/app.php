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
            Route::middleware('web')->group(__DIR__.'/../routes/tenant.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Payment gateways (e.g. Yo! IPNs) post here from their own servers.
        $middleware->validateCsrfTokens(except: ['payments/callback/*']);

        $middleware->alias([
            'check-active' => \App\Http\Middleware\CheckActive::class,
            'must-change-password' => \App\Http\Middleware\MustChangePassword::class,
            'admin' => \App\Http\Middleware\AdminOnly::class,
            'feature' => \App\Http\Middleware\RequirePlanFeature::class,
            'resolve-landlord' => \App\Http\Middleware\ResolveLandlord::class,
            'staff.check-active' => \App\Http\Middleware\StaffCheckActive::class,
            'staff.must-change-password' => \App\Http\Middleware\StaffMustChangePassword::class,
            'staff.admin' => \App\Http\Middleware\StaffAdminOnly::class,
            'guest.staff' => \App\Http\Middleware\RedirectIfStaffAuthenticated::class,
            'auth.staff' => \App\Http\Middleware\AuthenticateStaff::class,
            'staff.landlord-context' => \App\Http\Middleware\ResolveStaffLandlordContext::class,
            'staff.can' => \App\Http\Middleware\StaffCan::class,
            'auth.tenant' => \App\Http\Middleware\AuthenticateTenant::class,
            'subscription.access' => \App\Http\Middleware\EnforceSubscriptionAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An expired session (a form left open for hours, a phone restoring an
        // old tab) fails the CSRF check with a bare "419 Page Expired". Send
        // people back to the form with what they typed and a note instead.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            // Signing out with an expired session: they're already signed out.
            foreach (['logout' => 'login', 'staff.logout' => 'staff.login', 'tenant.logout' => 'tenant.login'] as $logout => $login) {
                if ($request->routeIs($logout)) {
                    return redirect()->route($login);
                }
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->with('error', 'Your session expired, so that wasn’t saved. Please try again.');
        });
    })->create();
