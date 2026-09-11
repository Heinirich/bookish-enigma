<?php

namespace App\Agent;

use App\Models\Evidence;
use App\Models\Investigation;
use Illuminate\Support\Collection;

/**
 * The single door through which facts reach the model.
 *
 * Nothing is shown to the model that has not first been written here and given
 * a public handle (EV-1, EV-2, ...). That ordering is what makes citation
 * checkable: the set of legitimate references is fixed and recorded *before*
 * generation, so any identifier the model produces outside that set is provably
 * invented rather than merely unverified.
 */
class EvidenceLedger
{
    public function record(
        Investigation $investigation,
        string $source,
        string $kind,
        string $summary,
        array $payload,
        ?string $sourceRef = null,
        ?string $sourceUrl = null,
    ): Evidence {
        $hash = hash('sha256', json_encode([$source, $kind, $sourceRef, $payload]));

        // Re-fetching the same fact must not mint a second identifier, or the
        // model can cite two IDs for one observation and appear better corroborated.
        $existing = Evidence::where('investigation_id', $investigation->id)
            ->where('hash', $hash)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Evidence::create([
            'investigation_id' => $investigation->id,
            'public_id' => $this->nextPublicId($investigation),
            'source' => $source,
            'kind' => $kind,
            'source_ref' => $sourceRef,
            'source_url' => $sourceUrl,
            'summary' => $summary,
            'payload' => $payload,
            'hash' => $hash,
            'fetched_at' => now(),
        ]);
    }

    /** @return Collection<int,Evidence> */
    public function all(Investigation $investigation): Collection
    {
        return Evidence::where('investigation_id', $investigation->id)
            ->orderBy('id')
            ->get();
    }

    /** @return array<int,string> The only citations that can be legitimate. */
    public function publicIds(Investigation $investigation): array
    {
        return $this->all($investigation)->pluck('public_id')->all();
    }

    public function find(Investigation $investigation, string $publicId): ?Evidence
    {
        return Evidence::where('investigation_id', $investigation->id)
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * The evidence block placed in the model's context. Compact by design: a 4B
     * model reasons far better over a tight digest than a raw API dump.
     */
    public function renderContext(Investigation $investigation): string
    {
        $evidence = $this->all($investigation);

        if ($evidence->isEmpty()) {
            return "(no evidence gathered)\n";
        }

        return $evidence
            ->map(fn (Evidence $e) => $this->renderOne($e))
            ->implode("\n");
    }

    private function renderOne(Evidence $e): string
    {
        $lines = ["[{$e->public_id}] ({$e->source}/{$e->kind}) {$e->summary}"];

        foreach ($this->flatten($e->payload) as $key => $value) {
            $lines[] = "    {$key}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Flatten a payload into scalar leaf lines, so every number the model might
     * quote is visible verbatim in its context and the grounding check has a
     * concrete string to match against.
     */
    private function flatten(array $payload, string $prefix = '', int $depth = 0): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $label = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                if ($depth >= 2) {
                    $flat[$label] = $this->scalarList($value);

                    continue;
                }

                if ($this->isScalarList($value)) {
                    $flat[$label] = $this->scalarList($value);

                    continue;
                }

                $flat += $this->flatten($value, $label, $depth + 1);

                continue;
            }

            $flat[$label] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $flat;
    }

    private function isScalarList(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private function scalarList(array $value): string
    {
        $flat = [];

        array_walk_recursive($value, function ($item) use (&$flat) {
            $flat[] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
        });

        return implode(', ', array_slice($flat, 0, 12))
            .(count($flat) > 12 ? ' … ('.count($flat).' items)' : '');
    }

    private function nextPublicId(Investigation $investigation): string
    {
        $count = Evidence::where('investigation_id', $investigation->id)->count();

        return 'EV-'.($count + 1);
    }
}
