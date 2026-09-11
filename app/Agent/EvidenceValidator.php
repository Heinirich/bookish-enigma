<?php

namespace App\Agent;

use App\Models\Evidence;
use App\Models\Investigation;
use Illuminate\Support\Collection;

/**
 * Checks a generated claim against the record it says it came from.
 *
 * Two independent failure modes, checked separately because they fail
 * differently:
 *
 *   Fabricated citation -- the model references EV-9 when only EV-1..EV-6 were
 *   ever shown. Fatal: the claim rests on nothing and is rejected outright.
 *
 *   Ungrounded figure -- the citation is real, but a number in the prose does
 *   not appear in the cited payload. Not fatal (the model may be paraphrasing
 *   or rounding) but scored, surfaced, and available for ranking.
 */
class EvidenceValidator
{
    /** Numbers this small are ordinary counts, not quantitative claims worth checking. */
    private const TRIVIAL_NUMBER_CEILING = 10;

    /** A figure counts as grounded if within this fraction of a number in the evidence. */
    private const NUMERIC_TOLERANCE = 0.05;

    public function __construct(private readonly EvidenceLedger $ledger) {}

    public function validate(Investigation $investigation, array $hypothesis): ValidationReport
    {
        $cited = array_values(array_unique(array_map(
            fn ($id) => trim((string) $id),
            array_merge(
                $hypothesis['evidence_ids'] ?? [],
                $hypothesis['contradicting_evidence_ids'] ?? [],
            ),
        )));

        $available = $this->ledger->all($investigation);
        $known = $available->pluck('public_id')->all();

        $valid = array_values(array_intersect($cited, $known));
        $fabricated = array_values(array_diff($cited, $known));

        $citedEvidence = $available->whereIn('public_id', $valid);

        [$grounded, $ungrounded] = $this->checkGrounding(
            (string) ($hypothesis['statement'] ?? ''),
            $citedEvidence,
        );

        $tokenCount = count($grounded) + count($ungrounded);

        return new ValidationReport(
            cited: $cited,
            valid: $valid,
            fabricated: $fabricated,
            groundedTokens: $grounded,
            ungroundedTokens: $ungrounded,
            groundingScore: $tokenCount === 0 ? 1.0 : round(count($grounded) / $tokenCount, 3),
        );
    }

    /**
     * Every quantitative token in the statement must be traceable to the text of
     * the evidence it cites.
     *
     * @param  Collection<int,Evidence>  $citedEvidence
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private function checkGrounding(string $statement, Collection $citedEvidence): array
    {
        $tokens = $this->extractClaimTokens($statement);

        if ($tokens === []) {
            return [[], []];
        }

        $haystack = strtolower($citedEvidence
            ->map(fn (Evidence $e) => $e->summary.' '.$e->source_ref.' '.json_encode($e->payload))
            ->implode(' '));

        $haystackNumbers = $this->extractNumbers($haystack);

        $grounded = [];
        $ungrounded = [];

        foreach ($tokens as $token) {
            $this->isGrounded($token, $haystack, $haystackNumbers)
                ? $grounded[] = $token
                : $ungrounded[] = $token;
        }

        return [$grounded, $ungrounded];
    }

    private function isGrounded(string $token, string $haystack, array $haystackNumbers): bool
    {
        $needle = strtolower($token);

        // SHAs and other identifiers: a literal appearance is the whole test.
        if (! is_numeric($needle)) {
            return str_contains($haystack, $needle);
        }

        /*
        | Figures are compared numerically rather than as substrings. A substring
        | test would call "40" grounded because "1040" happens to appear
        | somewhere in the payload, which is exactly the kind of false pass this
        | gate exists to prevent.
        |
        | Sensible rounding still counts: "26x" against a measured 25.84x is a
        | fair paraphrase, not an invention.
        */
        $value = (float) $needle;

        foreach ($haystackNumbers as $candidate) {
            if ($candidate === $value) {
                return true;
            }

            if ($candidate == 0.0) {
                continue;
            }

            if (abs($candidate - $value) / abs($candidate) <= self::NUMERIC_TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    /** Figures and SHAs -- the parts of a statement that assert something checkable. */
    private function extractClaimTokens(string $statement): array
    {
        $tokens = [];

        // Commit SHAs: 7-40 hex chars, and not a plain decimal number.
        preg_match_all('/\b(?=[0-9a-f]*[a-f])[0-9a-f]{7,40}\b/i', $statement, $shas);
        foreach ($shas[0] as $sha) {
            $tokens[] = $sha;
        }

        /*
        | No trailing \b: latency claims are written "1012ms", "26x", "40%", and
        | a trailing word boundary fails against the unit suffix. That silently
        | extracted zero tokens from the most common phrasing there is, so every
        | such statement scored a vacuous grounding of 1.00.
        |
        | The leading (?<![\w.]) keeps "95" in "p95" and the digits inside a SHA
        | from being read as quantitative claims.
        */
        preg_match_all('/(?<![\w.])\d+(?:\.\d+)?/', $statement, $numbers);
        foreach ($numbers[0] as $number) {
            if (str_contains($number, '.') || (float) $number >= self::TRIVIAL_NUMBER_CEILING) {
                $tokens[] = $number;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function extractNumbers(string $text): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $text, $matches);

        return array_map('floatval', $matches[0]);
    }
}
