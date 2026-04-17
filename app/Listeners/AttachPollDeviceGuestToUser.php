<?php

namespace App\Listeners;

use App\Events\UserLoginEvent;
use App\Events\UserRegisterEvent;
use App\Models\Guest;
use Illuminate\Support\Str;

class AttachPollDeviceGuestToUser
{
    public function handle(UserLoginEvent|UserRegisterEvent $event): void
    {
        $deviceId = $event->deviceId;
        if ($deviceId === null || $deviceId === "" || ! Str::isUuid($deviceId)) {
            return;
        }

        Guest::query()
            ->where("device_id", $deviceId)
            ->whereNull("user_id")
            ->update(["user_id" => $event->user->id]);
    }
}
