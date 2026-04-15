<?php

namespace App\Repositories;

use App\Models\Guest;

class GuestRepo
{
    public static function firstOrCreateByUserOrIp(?int $userId, string $ip, ?string $userAgent): Guest
    {
        if ($userId) {
            $guest = Guest::where("user_id", $userId)->first();

            if ($guest) {
                return $guest;
            }

            // Logged-in voters are keyed by user_id only. Do not reuse a row matched by IP
            // (e.g. 127.0.0.1 for every local browser), or different accounts share one guest.
            return Guest::create([
                "user_id" => $userId,
                "ip" => $ip,
                "user_agent" => $userAgent,
            ]);
        }

        $guestByIp = Guest::where("ip", $ip)->whereNull("user_id")->first();

        if ($guestByIp) {
            return $guestByIp;
        }

        return Guest::create([
            "user_id" => null,
            "ip" => $ip,
            "user_agent" => $userAgent,
        ]);
    }

    public static function find(?int $userId, string $ip)
    {
        if ($userId) {
            return Guest::query()
                ->where("user_id", $userId)
                ->first();
        }

        return Guest::query()
            ->where("ip", $ip)
            ->whereNull("user_id")
            ->first();
    }
}
