<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commit extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'changed_files' => 'array',
            'additions' => 'integer',
            'deletions' => 'integer',
        ];
    }

    public function deployment(): ?Deployment
    {
        return Deployment::where('sha', $this->sha)->first();
    }
}
