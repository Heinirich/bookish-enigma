<?php

namespace App\Console\Commands;

use App\Models\HealthCheck;
use App\Models\MonitoredEndpoint;
use App\Models\ScheduleSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The metrics source. Every sample records the latency, the status, and the
 * deploy that was live at the time -- so "which build was serving when this
 * got slow" is a recorded fact, not an inference.
 */
class HealthPingCommand extends Command
{
    protected $signature = 'health:ping
                            {--watch : Poll continuously at the configured interval}
                            {--samples=1 : Samples per endpoint per invocation}
                            {--scheduled : Respect the schedule settings and skip if not yet due}';

    protected $description = 'Ping monitored endpoints and record latency samples';

    /** Stop before the next scheduler tick so withoutOverlapping() never skips a run. */
    private const MAX_TICK_SECONDS = 55;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    /** Seconds between samples within one invocation; 0 means fire back to back. */
    private int $spacingSeconds = 0;

    public function handle(): int
    {
        $settings = ScheduleSetting::current();

        // Under the scheduler this runs every minute; the configured interval is
        // enforced here so it can be changed from the UI without a redeploy.
        if ($this->option('scheduled')) {
            if (! $settings->monitoring_enabled) {
                return self::SUCCESS;
            }

            $settings->update(['last_ping_at' => now()]);

            // A sub-minute interval means several samples per scheduler tick.
            $this->input->setOption(
                'samples',
                max(1, (int) floor(60 / max(1, $settings->ping_interval_seconds))),
            );

            $this->spacingSeconds = max(1, $settings->ping_interval_seconds);
        }

        $endpoints = MonitoredEndpoint::where('is_active', true)->get();

        if ($endpoints->isEmpty()) {
            $this->warn('No active monitored endpoints. Run: php artisan db:seed');

            return self::FAILURE;
        }

        if ($this->option('watch')) {
            return $this->watch($endpoints, $settings);
        }

        $samples = max(1, (int) $this->option('samples'));

        $startedAt = microtime(true);

        foreach ($endpoints as $endpoint) {
            for ($i = 0; $i < $samples; $i++) {
                $this->render($endpoint, $this->ping($endpoint));

                /*
                | Space the samples out across the tick.
                |
                | Without this all six "10-second" samples fired inside the first
                | six seconds of the minute and nothing followed for the other
                | fifty-four. Per-minute buckets still looked right, but a
                | degradation starting mid-minute went unseen until the next tick,
                | and the changepoint refinement -- which looks for elevation
                | sustained across consecutive samples -- was reading a three
                | second window rather than a thirty second one.
                */
                if ($this->spacingSeconds > 0 && $i < $samples - 1) {
                    // Leave headroom so the run finishes before the next tick,
                    // which withoutOverlapping() would otherwise skip.
                    if ((microtime(true) - $startedAt) + $this->spacingSeconds > self::MAX_TICK_SECONDS) {
                        break;
                    }

                    sleep($this->spacingSeconds);
                }
            }
        }

        return self::SUCCESS;
    }

    private function watch($endpoints, ScheduleSetting $settings): int
    {
        $this->info('Watching '.$endpoints->count().' endpoint(s) every '
            .$settings->ping_interval_seconds.'s. Ctrl+C to stop.');

        while (true) {
            // Re-read each pass so a cadence change in the UI takes effect on a
            // long-running watcher rather than at the next restart.
            $settings = $settings->fresh() ?? $settings;

            if ($settings->monitoring_enabled) {
                foreach ($endpoints as $endpoint) {
                    $this->render($endpoint, $this->ping($endpoint));
                }
            }

            sleep(max(1, $settings->ping_interval_seconds));
        }
    }

    private function ping(MonitoredEndpoint $endpoint): HealthCheck
    {
        $startedAt = microtime(true);
        $statusCode = null;
        $deploySha = null;
        $checks = null;
        $errorMessage = null;

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->acceptJson()->get($endpoint->url);
            $statusCode = $response->status();

            $body = $response->json();
            if (is_array($body)) {
                $deploySha = $body['deploy_sha'] ?? null;
                $checks = $body['checks'] ?? null;
                $errorMessage = $body['error'] ?? null;
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        /*
        | A sample far longer than the HTTP timeout is not measuring the service.
        |
        | Wall clock keeps advancing while the host is suspended, so a request in
        | flight when the laptop sleeps comes back reporting minutes. Two such
        | samples -- 998s and 301s against a 15s timeout -- were enough to flatten
        | every latency chart, and one inside a baseline window would push p95 so
        | high that a real degradation could not trip the detector.
        |
        | The attempt is still recorded, because the endpoint genuinely did not
        | answer, but the duration is clamped to something the timeout could
        | actually have produced.
        */
        $ceilingMs = self::REQUEST_TIMEOUT_SECONDS * 1000;

        if ($elapsedMs > $ceilingMs * 2) {
            $errorMessage = 'Measurement discarded: host suspended mid-request ('
                .round($elapsedMs / 1000).'s wall clock against a '
                .self::REQUEST_TIMEOUT_SECONDS.'s timeout)';
            $elapsedMs = $ceilingMs;
            $statusCode = null;
        }

        $latencyMs = $elapsedMs;

        return HealthCheck::create([
            'monitored_endpoint_id' => $endpoint->id,
            'checked_at' => now(),
            'status_code' => $statusCode,
            'latency_ms' => $latencyMs,
            'is_error' => $statusCode !== $endpoint->expected_status,
            'error_message' => $errorMessage,
            'deploy_sha' => $deploySha,
            'checks' => $checks,
        ]);
    }

    private function render(MonitoredEndpoint $endpoint, HealthCheck $check): void
    {
        $status = $check->is_error
            ? "<fg=red>{$check->status_code}</>"
            : "<fg=green>{$check->status_code}</>";

        $latency = $check->latency_ms > 500
            ? "<fg=yellow>{$check->latency_ms}ms</>"
            : "{$check->latency_ms}ms";

        $this->line(sprintf(
            '%s  %-14s %s  %s  %s',
            now()->format('H:i:s'),
            $endpoint->slug,
            $status,
            str_pad($latency, 18),
            $check->deploy_sha ? substr($check->deploy_sha, 0, 7) : '—',
        ));
    }
}
