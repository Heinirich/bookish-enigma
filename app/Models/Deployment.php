<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deployed_at' => 'datetime',
            'chaos_profile' => 'array',
            'is_seeded_cause' => 'boolean',
        ];
    }

    public function commit(): BelongsTo
    {
        return $this->belongsTo(Commit::class, 'sha', 'sha');
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('deployed_at', [$from, $to]);
    }

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }
}
