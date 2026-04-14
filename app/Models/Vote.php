<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    protected $fillable = ["poll_id", "poll_option_id", "guest_id"];

    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    public function option()
    {
        return $this->belongsTo(PollOption::class, "poll_option_id");
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }
}
