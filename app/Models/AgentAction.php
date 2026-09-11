<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'was_write' => 'boolean',
            'approved_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }
}
