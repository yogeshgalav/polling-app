<?php

namespace App\Repositories;

use App\Models\Guest;

class GuestRepo
{
    public static function firstOrCreateByUserOrDeviceId(
        ?int $userId,
        ?string $deviceId,
        ?string $ip,
        ?string $userAgent,
    ): Guest {
        if ($userId) {
            $guest = Guest::where("user_id", $userId)->first();

            if ($guest) {
                return $guest;
            }

            // Logged-in voters are keyed by user_id only. Do not reuse a row matched by device/IP
            // (e.g. shared browsers or local testing), or different accounts share one guest.
            return Guest::create([
                "user_id" => $userId,
                "device_id" => $deviceId,
                "ip" => $ip,
                "user_agent" => $userAgent,
            ]);
        }

        if ($deviceId !== null) {
            $guestByDevice = Guest::where("device_id", $deviceId)
                ->whereNull("user_id")
                ->first();

            if ($guestByDevice) {
                return $guestByDevice;
            }
        }

        return Guest::create([
            "user_id" => null,
            "device_id" => $deviceId,
            "ip" => $ip,
            "user_agent" => $userAgent,
        ]);
    }

    public static function find(?int $userId, ?string $deviceId)
    {
        if ($userId) {
            return Guest::query()
                ->where("user_id", $userId)
                ->first();
        }

        if ($deviceId === null) {
            return null;
        }

        return Guest::query()
            ->where("device_id", $deviceId)
            ->whereNull("user_id")
            ->first();
    }
}
