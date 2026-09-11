<?php

namespace App\Connectors\Contracts;

use Illuminate\Support\Carbon;

interface GitHubConnector
{
    /** Pull deployments and their commits into local storage for the window. */
    public function sync(Carbon $from, Carbon $to): int;

    /** @return array<string,mixed>|null */
    public function commit(string $sha): ?array;

    public function isConfigured(): bool;
}
