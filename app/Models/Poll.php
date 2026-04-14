<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    protected $fillable = ["title", "slug", "created_by", "expires_at"];

    public function getRouteKeyName(): string
    {
        return "slug";
    }

    protected function casts(): array
    {
        return [
            "expires_at" => "datetime",
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, "created_by");
    }

    public function isOpen(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isFuture();
    }
}
