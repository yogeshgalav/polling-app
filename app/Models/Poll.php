<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Poll extends Model
{
    //
    protected $fillable = ["question", "created_by", "uuid", "expires_at"];
}
