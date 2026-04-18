<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class PollDeviceId
{
    public const COOKIE_NAME = "poll_device_id";

    /**
     * Read the device id from the request cookie bag or raw Cookie header (no mutation).
     */
    public static function read(Request $request): ?string
    {
        $cookie_device_id = $request->cookie(self::COOKIE_NAME);
        if (!empty($cookie_device_id)) {
            return $cookie_device_id;
        }

        $raw =
            $request->headers->get("cookie") ??
            (string) $request->server("HTTP_COOKIE");
        if (! is_string($raw) || $raw === "") {
            return null;
        }

        if (preg_match('/(?:^|;\s*)poll_device_id=([^;]+)/', $raw, $m)) {
            $parsed = urldecode($m[1]);
            if (is_string($parsed) && $parsed !== "") {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Return a non-empty device id and mirror it onto the request cookie bag (generates if missing).
     */
    public static function get(Request $request): string
    {
        $id = self::read($request);
        if ($id !== null && Str::isUuid($id)) {
            $request->cookies->set(self::COOKIE_NAME, $id);

            return $id;
        }

        $id = (string) Str::uuid();
        $request->cookies->set(self::COOKIE_NAME, $id);

        return $id;
    }

    public static function makeCookie(string $value): Cookie
    {
        return cookie(
            self::COOKIE_NAME,
            $value,
            60 * 24 * 365 * 5,
            "/",
            null,
            false,
            true,
            false,
            "Lax",
        );
    }
}
