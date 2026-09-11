<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Incident extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'detection_signal' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function monitoredEndpoint(): BelongsTo
    {
        return $this->belongsTo(MonitoredEndpoint::class);
    }

    public function investigations(): HasMany
    {
        return $this->hasMany(Investigation::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Statuses an investigation passes through before it settles. */
    public const RUNNING_STATUSES = ['queued', 'gathering', 'exploring', 'synthesizing', 'acting'];

    public function hasRunningInvestigation(): bool
    {
        return $this->investigations()->whereIn('status', self::RUNNING_STATUSES)->exists();
    }

    public function runningInvestigation(): ?Investigation
    {
        return $this->investigations()->whereIn('status', self::RUNNING_STATUSES)->latest('id')->first();
    }

    public function latestInvestigation(): HasOne
    {
        return $this->hasOne(Investigation::class)->latestOfMany();
    }
}
