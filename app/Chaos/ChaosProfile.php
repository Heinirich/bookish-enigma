<?php

namespace App\Chaos;

use Illuminate\Support\Facades\Cache;

/**
 * The live shape of /healthz responses. Held in the cache rather than the
 * database so a flip takes effect on the very next ping with no migration,
 * no restart, and no deploy.
 */
class ChaosProfile
{
    public function __construct(
        public readonly ?string $scenarioKey,
        public readonly string $label,
        public readonly int $baseLatencyMs,
        public readonly int $jitterMs,
        public readonly float $errorRate,
        public readonly ?string $deploySha = null,
        public readonly ?string $activatedAt = null,
    ) {}

    public static function current(): self
    {
        $data = Cache::get(config('health.chaos_cache_key'))
            ?? config('health.default_profile');

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $defaults = config('health.default_profile');

        return new self(
            scenarioKey: $data['scenario_key'] ?? $defaults['scenario_key'],
            label: $data['label'] ?? $defaults['label'],
            baseLatencyMs: (int) ($data['base_latency_ms'] ?? $defaults['base_latency_ms']),
            jitterMs: (int) ($data['jitter_ms'] ?? $defaults['jitter_ms']),
            errorRate: (float) ($data['error_rate'] ?? $defaults['error_rate']),
            deploySha: $data['deploy_sha'] ?? null,
            activatedAt: $data['activated_at'] ?? null,
        );
    }

    public function activate(): void
    {
        Cache::forever(config('health.chaos_cache_key'), $this->toArray());
    }

    public static function reset(): self
    {
        $profile = self::fromArray(config('health.default_profile'));
        $profile->activate();

        return $profile;
    }

    public function toArray(): array
    {
        return [
            'scenario_key' => $this->scenarioKey,
            'label' => $this->label,
            'base_latency_ms' => $this->baseLatencyMs,
            'jitter_ms' => $this->jitterMs,
            'error_rate' => $this->errorRate,
            'deploy_sha' => $this->deploySha,
            'activated_at' => $this->activatedAt,
        ];
    }

    /** Latency this request should take, in milliseconds. */
    public function sampleLatencyMs(): int
    {
        return $this->baseLatencyMs + ($this->jitterMs > 0 ? random_int(0, $this->jitterMs) : 0);
    }

    public function shouldFail(): bool
    {
        return $this->errorRate > 0 && (random_int(1, 10_000) / 10_000) <= $this->errorRate;
    }

    public function isHealthy(): bool
    {
        return $this->scenarioKey === null;
    }
}
