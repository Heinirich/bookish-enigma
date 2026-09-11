<?php

namespace App\Monitoring;

use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Opens an incident from a typed prompt rather than from the detector.
 *
 * The premise of the project is a vague alert -- "investigate the payment API
 * latency spike" -- becoming a documented investigation, so a human has to be
 * able to start one without waiting for a threshold to trip.
 *
 * The prompt is passed through to the agent untouched. What this class adds is
 * the same measured signal the detector would have attached, so a manual
 * investigation reasons over real numbers rather than over the phrasing of the
 * request.
 */
class ManualIncidentOpener
{
    public function __construct(private readonly IncidentDetector $detector) {}

    public function open(
        string $prompt,
        ?MonitoredEndpoint $endpoint = null,
        int $windowMinutes = 30,
        ?Carbon $now = null,
    ): Incident {
        $now ??= now();
        $endpoint ??= MonitoredEndpoint::where('is_active', true)->firstOrFail();

        $signal = $this->detector->measure($endpoint, $now, [
            'baseline_window_minutes' => $windowMinutes,
            'spike_window_minutes' => config('agent.detection.spike_window_minutes'),
            'min_baseline_samples' => 1,
        ]);

        return Incident::create([
            'reference' => 'INC-'.strtoupper(Str::random(6)),
            'title' => $this->title($prompt, $signal),
            'prompt' => trim($prompt),
            'monitored_endpoint_id' => $endpoint->id,
            'trigger' => 'manual',
            'severity' => $this->severity($signal),
            'status' => 'open',
            'detected_at' => $now,
            'window_start' => $now->copy()->subMinutes($windowMinutes),
            'window_end' => $now,
            'detection_signal' => $signal ? $signal + ['reason' => 'manual'] : ['reason' => 'manual'],
        ]);
    }

    /**
     * Prefer what the data shows over what the prompt claims -- a person can
     * report a "latency spike" when the real symptom is an error rate, and the
     * title should reflect the measurement.
     */
    private function title(string $prompt, ?array $signal): string
    {
        if ($signal && ($signal['latency_ratio'] ?? 0) >= 2) {
            return "Reported: p95 latency up {$signal['latency_ratio']}x";
        }

        if ($signal && ($signal['spike']['error_rate'] ?? 0) >= 0.1) {
            return 'Reported: '.round($signal['spike']['error_rate'] * 100).'% error rate';
        }

        return 'Reported: '.Str::limit(trim($prompt), 60);
    }

    private function severity(?array $signal): string
    {
        return match (true) {
            ($signal['spike']['error_rate'] ?? 0) >= 0.2 => 'sev1',
            ($signal['latency_ratio'] ?? 0) >= 10 => 'sev1',
            ($signal['latency_ratio'] ?? 0) >= 3 => 'sev2',
            default => 'sev3',
        };
    }
}
