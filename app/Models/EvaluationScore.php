<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationScore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'root_cause_hit' => 'boolean',
            'top_confidence' => 'float',
            'grounding_rate' => 'float',
            'brier' => 'float',
            'actions_completed' => 'array',
        ];
    }

    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }
}
