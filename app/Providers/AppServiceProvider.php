<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * UPGRADE-v2 Phase 6.
     *
     * The spec assumed login rate limiting already existed — it did not; only
     * /health was throttled. This dashboard stores SSH credentials and panel
     * tokens for every server, so an unthrottled login form is the softest
     * target in the whole system.
     *
     * Keyed on email+IP so one attacker cannot lock out a legitimate user by
     * hammering their address from elsewhere.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by(Str::lower($email).'|'.$request->ip()),
                // Second limit catches distributed guessing against one account.
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
