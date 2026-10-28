<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        RateLimiter::for('auth-login', function (Request $request): array {
            $identity = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by("auth-login:{$identity}:{$request->ip()}"),
                Limit::perMinute(30)->by("auth-login-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('auth-register', fn (Request $request): array => [
            Limit::perMinute(3)->by("auth-register-minute:{$request->ip()}"),
            Limit::perHour(10)->by("auth-register-hour:{$request->ip()}"),
        ]);
    }
}
