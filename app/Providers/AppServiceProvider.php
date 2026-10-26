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
        // @staffcan('permission') … @endstaffcan — show staff-portal UI only to staff allowed to use it.
        // (Routes are guarded separately by the staff.can middleware.)
        \Illuminate\Support\Facades\Blade::if('staffcan', fn (string $permission) => (bool) \Illuminate\Support\Facades\Auth::guard('staff')->user()?->hasPermission($permission));
    }
}
