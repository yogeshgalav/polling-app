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
        }

        $guestByIp = Guest::where("ip", $ip)->first();

        if ($guestByIp) {
            return $guestByIp;
        }

        return Guest::create([
            "user_id" => $userId,
            "ip" => $ip,
            "user_agent" => $userAgent,
        ]);
    }
}
