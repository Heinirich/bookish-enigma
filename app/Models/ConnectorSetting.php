<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY. Rotating that key orphans these
            // values, which is the intended trade for not storing tokens in plain text.
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function isApi(): bool
    {
        return $this->driver === 'api';
    }

    public function credential(string $field): ?string
    {
        return ($this->credentials ?? [])[$field] ?? null;
    }
}
