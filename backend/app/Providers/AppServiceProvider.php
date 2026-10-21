<?php

namespace App\Providers;

use App\Services\Auth\JwksSocialVerifier;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Nutrition\AdaptiveTargetService;
use App\Services\Nutrition\TargetCalculator;
use App\Services\Nutrition\TrendService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // We ship our own personal_access_tokens migration (UUID tokenable ids).


        $this->app->singleton(TargetCalculator::class, fn() => TargetCalculator::fromConfig());
        $this->app->singleton(TrendService::class, fn() => new TrendService(config('fitness.trend.alpha')));
        $this->app->singleton(AdaptiveTargetService::class, fn($app) => new AdaptiveTargetService(
            $app->make(TargetCalculator::class),
            config('fitness.adaptive'),
            config('fitness.kcal_per_kg'),
        ));
        $this->app->bind(SocialTokenVerifier::class, JwksSocialVerifier::class);
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn(Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn(Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('uploads', fn(Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));

        // The mobile app handles this link (deep link / universal link).
        ResetPassword::createUrlUsing(fn($user, string $token) => rtrim(config('app.url'), '/')
            . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset()));
    }
}
