<?php

namespace App\Http\Middleware;

use App\Support\PollDeviceId;
use Closure;
use Illuminate\Http\Request;

class EnsurePollDeviceId
{
    public function handle(Request $request, Closure $next)
    {
        $deviceId = PollDeviceId::get($request);

        $response = $next($request);

        $response->headers->setCookie(PollDeviceId::makeCookie($deviceId));

        return $response;
    }
}

