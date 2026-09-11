<?php

namespace App\Monitoring;

use App\Chaos\ScenarioLibrary;
use App\Models\Deployment;
use App\Models\HealthCheck;
use App\Models\MonitoredEndpoint;
use Illuminate\Support\Carbon;

/**
 * Writes a retroactive sample history so a scenario can be evaluated in seconds
 * rather than in the 30 real minutes the detector's baseline window needs.
 *
 * Samples before the culprit deploy are healthy; samples after carry that
 * scenario's latency and error profile. Each sample is stamped with whichever
 * deploy was live at that instant, exactly as a live ping would record it.
 */
class SyntheticHistory
{
    public function generate(
        MonitoredEndpoint $endpoint,
        string $scenarioKey,
        ?Carbon $now = null,
        int $historyMinutes = 35,
        int $intervalSeconds = 10,
    ): int {
        $scenario = ScenarioLibrary::get($scenarioKey);

        if ($scenario === null) {
            throw new \InvalidArgumentException("Unknown scenario [{$scenarioKey}].");
        }

        $now ??= now();

        // Degradation onset is declared by the scenario rather than inferred from
        // the culprit deploy, so a scenario can place the break far away from any
        // deployment -- which is the whole point of the no-deploy-cause case.
        $breakAt = array_key_exists('break_offset_minutes', $scenario)
            ? $now->copy()->addMinutes($scenario['break_offset_minutes'])
            : ($this->culpritDeployment($scenarioKey)?->deployed_at ?? $now->copy()->subMinutes(3));

        $deployTimeline = Deployment::where('scenario_key', $scenarioKey)
            ->orderBy('deployed_at')
            ->get(['sha', 'deployed_at']);

        $healthy = config('health.default_profile');
        $rows = [];
        $cursor = $now->copy()->subMinutes($historyMinutes);

        while ($cursor->lessThanOrEqualTo($now)) {
            $degraded = $cursor->greaterThanOrEqualTo($breakAt);

            $profile = $degraded ? $scenario['profile'] : $healthy;

            $latency = $profile['base_latency_ms']
                + ($profile['jitter_ms'] > 0 ? random_int(0, $profile['jitter_ms']) : 0);

            $isError = $profile['error_rate'] > 0
                && (random_int(1, 10_000) / 10_000) <= $profile['error_rate'];

            $rows[] = [
                'monitored_endpoint_id' => $endpoint->id,
                'checked_at' => $cursor->copy(),
                'status_code' => $isError ? 503 : 200,
                'latency_ms' => $latency,
                'is_error' => $isError,
                'error_message' => $isError ? 'upstream_unavailable' : null,
                'deploy_sha' => $this->shaLiveAt($deployTimeline, $cursor),
                'checks' => json_encode(['database' => ['ok' => true], 'redis' => ['ok' => true]]),
            ];

            $cursor->addSeconds($intervalSeconds);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            HealthCheck::insert($chunk);
        }

        return count($rows);
    }

    public function culpritDeployment(string $scenarioKey): ?Deployment
    {
        return Deployment::where('scenario_key', $scenarioKey)
            ->where('is_seeded_cause', true)
            ->latest('deployed_at')
            ->first();
    }

    private function shaLiveAt($deployments, Carbon $at): ?string
    {
        $live = null;

        foreach ($deployments as $deployment) {
            if ($deployment->deployed_at->lessThanOrEqualTo($at)) {
                $live = $deployment->sha;
            }
        }

        return $live;
    }
}
