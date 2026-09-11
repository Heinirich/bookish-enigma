<?php

namespace App\Monitoring;

use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Models\ScheduleSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a stream of latency samples into an incident.
 *
 * Deliberately dumb and deterministic: rolling p95 of the recent window against
 * the preceding baseline, plus an error-rate rule. No model involved -- detection
 * has to be explainable on its own terms before the agent is handed anything.
 */
class IncidentDetector
{
    /**
     * Detection thresholds, with the operator-controlled ones read from the
     * database rather than the config file.
     *
     * Cooldown is adjustable from the Schedule page, so reading it from config
     * meant a long-running worker kept using whatever value was loaded at boot.
     */
    private function config(): array
    {
        return array_merge(config('agent.detection'), [
            'cooldown_minutes' => ScheduleSetting::current()->incident_cooldown_minutes,
        ]);
    }

    public function detect(MonitoredEndpoint $endpoint, ?Carbon $now = null): ?Incident
    {
        $now ??= now();
        $config = $this->config();

        if ($this->inCooldown($endpoint, $now, $config['cooldown_minutes'])) {
            return null;
        }

        $signal = $this->measure($endpoint, $now, $config);

        if ($signal === null) {
            return null;
        }

        $reason = $this->evaluate($signal, $config);

        if ($reason === null) {
            return null;
        }

        return $this->openIncident($endpoint, $now, $signal, $reason, $config);
    }

    /** Raw numbers for the window, or null when there is not enough history to judge. */
    public function measure(MonitoredEndpoint $endpoint, Carbon $now, ?array $config = null): ?array
    {
        $config ??= $this->config();

        $spikeStart = $now->copy()->subMinutes($config['spike_window_minutes']);
        $baselineStart = $now->copy()->subMinutes($config['baseline_window_minutes']);

        $baseline = $this->window($endpoint, $baselineStart, $spikeStart);
        $spike = $this->window($endpoint, $spikeStart, $now);

        if ($baseline->samples < $config['min_baseline_samples'] || $spike->samples < 3) {
            return null;
        }

        return [
            'baseline' => (array) $baseline,
            'spike' => (array) $spike,
            'latency_ratio' => $baseline->p95 > 0 ? round($spike->p95 / $baseline->p95, 2) : null,
            'window_start' => $baselineStart->toIso8601String(),
            'window_end' => $now->toIso8601String(),
            'spike_start' => $spikeStart->toIso8601String(),
        ];
    }

    private function window(MonitoredEndpoint $endpoint, Carbon $from, Carbon $to): object
    {
        return DB::table('health_checks')
            ->selectRaw('
                count(*)                                                          as samples,
                coalesce(percentile_cont(0.95) within group (order by latency_ms), 0) as p95,
                coalesce(percentile_cont(0.50) within group (order by latency_ms), 0) as p50,
                coalesce(avg(latency_ms), 0)                                      as mean,
                coalesce(avg(case when is_error then 1.0 else 0.0 end), 0)        as error_rate
            ')
            ->where('monitored_endpoint_id', $endpoint->id)
            ->where('checked_at', '>=', $from)
            ->where('checked_at', '<', $to)
            ->first();
    }

    private function evaluate(array $signal, array $config): ?string
    {
        $spike = $signal['spike'];
        $baseline = $signal['baseline'];

        if ($spike['error_rate'] >= $config['error_rate_threshold']) {
            return 'error_rate';
        }

        if ($baseline['p95'] > 0 && $spike['p95'] >= $baseline['p95'] * $config['latency_multiplier']) {
            return 'latency_p95';
        }

        return null;
    }

    private function inCooldown(MonitoredEndpoint $endpoint, Carbon $now, int $minutes): bool
    {
        return Incident::where('monitored_endpoint_id', $endpoint->id)
            ->whereIn('status', ['open', 'investigating'])
            ->where('detected_at', '>=', $now->copy()->subMinutes($minutes))
            ->exists();
    }

    private function openIncident(
        MonitoredEndpoint $endpoint,
        Carbon $now,
        array $signal,
        string $reason,
        array $config,
    ): Incident {
        $spike = $signal['spike'];

        [$title, $severity] = $reason === 'error_rate'
            ? [
                sprintf('%s returning %d%% errors', $endpoint->name, round($spike['error_rate'] * 100)),
                'sev1',
            ]
            : [
                sprintf('%s p95 latency up %sx', $endpoint->name, $signal['latency_ratio']),
                $signal['latency_ratio'] >= 10 ? 'sev1' : 'sev2',
            ];

        return Incident::create([
            'reference' => 'INC-'.strtoupper(Str::random(6)),
            'title' => $title,
            'prompt' => "Investigate the {$endpoint->service} ".
                ($reason === 'error_rate' ? 'error rate spike' : 'latency spike').'.',
            'monitored_endpoint_id' => $endpoint->id,
            'trigger' => 'auto',
            'severity' => $severity,
            'status' => 'open',
            'detected_at' => $now,
            // Widened so the investigation can see the deploys that preceded the spike.
            'window_start' => $now->copy()->subMinutes($config['baseline_window_minutes']),
            'window_end' => $now,
            'detection_signal' => $signal + ['reason' => $reason],
        ]);
    }
}
