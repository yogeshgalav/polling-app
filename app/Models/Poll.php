<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Poll extends Model
{
    //
    protected $fillable = ["question", "created_by", "uuid", "expires_at"];

    public function options()
    {
        return $this->hasMany(PollOption::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, "created_by");
    }
}
