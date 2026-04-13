<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    //
    protected $fillable = [
        "poll_id",
        "poll_option_id",
        "user_id",
        "device_id",
        "ip_address",
    ];
}
