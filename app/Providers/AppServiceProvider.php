<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Events\UserLoginEvent;
use App\Events\UserRegisterEvent;
use App\Listeners\AttachPollDeviceGuestToUser;
use App\Models\Poll;
use App\Policies\PollPolicy;
use App\Support\PollDeviceId;

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
        Event::listen(
            [UserLoginEvent::class, UserRegisterEvent::class],
            AttachPollDeviceGuestToUser::class,
        );

        Gate::policy(Poll::class, PollPolicy::class);

        RateLimiter::for("api", function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier() ?: $request->ip(),
            );
        });

        RateLimiter::for("poll-vote", function (Request $request) {
            $deviceId = PollDeviceId::get($request);

            return Limit::perSecond(3)->by($deviceId !== "" ? $deviceId : $request->ip());
        });

        Vite::prefetch(concurrency: 3);
    }
}
