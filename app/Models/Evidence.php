<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Evidence extends Model
{
    use HasFactory;

    protected $table = 'evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function hypotheses(): BelongsToMany
    {
        return $this->belongsToMany(Hypothesis::class, 'hypothesis_evidence')
            ->withPivot(['relation', 'note'])
            ->withTimestamps();
    }
}
