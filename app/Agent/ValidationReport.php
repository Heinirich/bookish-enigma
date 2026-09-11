<?php

namespace App\Agent;

class ValidationReport
{
    public function __construct(
        public readonly array $cited,
        public readonly array $valid,
        public readonly array $fabricated,
        public readonly array $groundedTokens,
        public readonly array $ungroundedTokens,
        public readonly float $groundingScore,
    ) {}

    public function passed(): bool
    {
        if ($this->fabricated !== [] || $this->valid === []) {
            return false;
        }

        /*
        | Partial grounding is tolerated -- a model may legitimately paraphrase.
        | But a statement whose figures are *all* absent from the evidence it
        | cites is not a checkable claim, whatever its citations look like. The
        | references are then decoration rather than support, which is precisely
        | the failure this project exists to catch.
        */
        return ! ($this->groundingScore == 0.0 && $this->ungroundedTokens !== []);
    }

    public function toArray(): array
    {
        return [
            'cited' => $this->cited,
            'valid' => $this->valid,
            'fabricated' => $this->fabricated,
            'grounded_tokens' => $this->groundedTokens,
            'ungrounded_tokens' => $this->ungroundedTokens,
            'grounding_score' => $this->groundingScore,
            'passed' => $this->passed(),
        ];
    }
}
