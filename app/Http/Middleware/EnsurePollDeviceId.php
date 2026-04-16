<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnsurePollDeviceId
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->cookie("poll_device_id")) {
            $rawCookie = $request->headers->get("cookie");
            if (is_string($rawCookie) && str_contains($rawCookie, "poll_device_id=")) {
                foreach (explode(";", $rawCookie) as $part) {
                    [$k, $v] = array_pad(explode("=", trim($part), 2), 2, null);
                    if ($k === "poll_device_id" && is_string($v) && $v !== "") {
                        $request->cookies->set("poll_device_id", urldecode($v));
                        break;
                    }
                }
            }
        }

        if (!$request->cookie("poll_device_id")) {
            $request->cookies->set("poll_device_id", (string) Str::uuid());
        }

        $response = $next($request);

        if ($request->cookie("poll_device_id")) {
            return $response;
        }

        $response->headers->setCookie(
            cookie(
                "poll_device_id",
                (string) Str::uuid(),
                60 * 24 * 365 * 5,
                "/",
                null,
                false,
                true,
                false,
                "Lax",
            ),
        );

        return $response;
    }
}

