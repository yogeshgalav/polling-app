<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollOption extends Model
{
    //
    protected $fillable = ["label", "vote_count"];
}
