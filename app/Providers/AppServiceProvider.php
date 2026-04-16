<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Models\Poll;
use App\Policies\PollPolicy;

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
        Gate::policy(Poll::class, PollPolicy::class);

        RateLimiter::for("poll-vote", function (Request $request) {
            $deviceId = $request->cookie("poll_device_id");

            return Limit::perSecond(3)->by(
                is_string($deviceId) && $deviceId !== "" ? $deviceId : $request->ip(),
            );
        });

        Vite::prefetch(concurrency: 3);
    }
}
