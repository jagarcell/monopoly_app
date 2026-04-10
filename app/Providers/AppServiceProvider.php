<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
     *
     * Logic: Configures Vite asset prefetching, registers the Apple Socialite
     * provider via the SocialiteWasCalled event so that Socialite::driver('apple')
     * is available throughout the application, and defines the 'api' rate limiter
     * (60 requests per minute per authenticated user or IP address) used by the
     * throttle:api middleware on API routes.
     *
     * @return void
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class);
        });

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
