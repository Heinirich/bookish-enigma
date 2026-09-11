<?php

namespace App\Agent\Tools;

/**
 * What a tool hands back: a human-readable line, a structured payload destined
 * for the evidence ledger, and provenance so the claim can be traced to source.
 */
class ToolResult
{
    public function __construct(
        public readonly string $summary,
        public readonly array $payload,
        public readonly string $source,
        public readonly string $kind,
        public readonly ?string $sourceRef = null,
        public readonly ?string $sourceUrl = null,
        public readonly bool $recordEvidence = true,
    ) {}

    public static function empty(string $summary, string $source, string $kind): self
    {
        return new self($summary, [], $source, $kind, recordEvidence: false);
    }
}
