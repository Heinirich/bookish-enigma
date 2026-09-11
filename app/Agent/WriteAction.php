<?php

namespace App\Agent;

/** Outcome of a write that reached a real external service. */
class WriteAction
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $externalRef,
        public readonly ?string $externalUrl,
        public readonly array $payload = [],
    ) {}
}
