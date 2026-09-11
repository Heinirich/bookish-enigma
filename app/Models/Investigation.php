<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    public function hypotheses(): HasMany
    {
        return $this->hasMany(Hypothesis::class)->orderBy('rank');
    }

    public function acceptedHypotheses(): HasMany
    {
        return $this->hypotheses()->where('status', 'accepted');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class)->orderBy('sequence');
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);
    }
}
