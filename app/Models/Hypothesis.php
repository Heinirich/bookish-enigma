<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hypothesis extends Model
{
    use HasFactory;

    protected $table = 'hypotheses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'grounding_score' => 'float',
            'validation_report' => 'array',
            'raw_evidence_ids' => 'array',
            'verdict_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function rootCauseDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'root_cause_deployment_id');
    }

    public function evidence(): BelongsToMany
    {
        return $this->belongsToMany(Evidence::class, 'hypothesis_evidence')
            ->withPivot(['relation', 'note'])
            ->withTimestamps();
    }

    public function isCause(): bool
    {
        return $this->stance !== 'ruled_out';
    }

    public function scopeCauses($query)
    {
        return $query->where('stance', 'cause');
    }

    public function isConfirmed(): bool
    {
        return $this->verdict === 'confirmed';
    }

    public function isRefuted(): bool
    {
        return $this->verdict === 'refuted';
    }

    public function awaitsVerdict(): bool
    {
        return $this->verdict === null;
    }

    public function wasRejected(): bool
    {
        return $this->status === 'rejected_unsupported';
    }
}
