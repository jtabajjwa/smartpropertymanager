<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Payments\PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every password a person sets (sign-up, reset, change, new users): 8+
        // characters with a letter, a number and a symbol.
        \Illuminate\Validation\Rules\Password::defaults(fn () => \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()->symbols());

        // @staffcan('permission') … @endstaffcan — show staff-portal UI only to staff allowed to use it.
        // (Routes are guarded separately by the staff.can middleware.)
        \Illuminate\Support\Facades\Blade::if('staffcan', fn (string $permission) => (bool) \Illuminate\Support\Facades\Auth::guard('staff')->user()?->hasPermission($permission));

        // Primora's books: post SMS purchases and manual settlements as they're recorded or changed.
        $keep = fn (string $method) => fn ($model) => \App\Accounting\BookKeeper::attempt(fn () => app(\App\Accounting\Postings::class)->{$method}($model));
        \App\Models\SmsPurchase::saved($keep('smsPurchaseSaved'));
        \App\Models\SmsPurchase::deleted($keep('smsPurchaseDeleted'));
        \App\Models\LandlordSettlement::saved($keep('settlementSaved'));
        \App\Models\LandlordSettlement::deleted($keep('settlementDeleted'));

        // Tenant portal limits, each step counted separately (a plain throttle:N,1
        // shares one counter across every route using it). Sign-in is per IP but
        // generous, since mobile networks put many phones behind one address;
        // codes also have per-phone limits in TenantLogin. Once signed in, limits
        // are per tenant.
        \Illuminate\Support\Facades\RateLimiter::for('tenant-auth', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('tenant-auth|'.$request->path().'|'.$request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('tenant-portal', function (\Illuminate\Http\Request $request) {
            $tenant = $request->session()->get(\App\Http\Middleware\AuthenticateTenant::SESSION)['tenant_id'] ?? $request->ip();
            $perMinute = $request->isMethod('GET') ? 60 : 10;

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by('tenant-portal|'.$request->route()?->getName().'|'.$tenant);
        });
    }
}
