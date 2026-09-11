<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'is_error' => 'boolean',
            'status_code' => 'integer',
            'latency_ms' => 'integer',
            'checks' => 'array',
        ];
    }

    public function monitoredEndpoint(): BelongsTo
    {
        return $this->belongsTo(MonitoredEndpoint::class);
    }
}
