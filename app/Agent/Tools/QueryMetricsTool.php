<?php

namespace App\Agent\Tools;

use App\Agent\Correlator;
use App\Models\Investigation;
use Illuminate\Support\Facades\DB;

class QueryMetricsTool implements AgentTool
{
    public function __construct(private readonly Correlator $correlator) {}

    public function name(): string
    {
        return 'query_metrics';
    }

    public function description(): string
    {
        return 'Get latency and error-rate statistics for the monitored service over a time window '
            .'ending at the incident. Returns p50, p95 and error rate, split before and after the '
            .'detected changepoint.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['window_minutes'],
            'properties' => [
                'window_minutes' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 240,
                    'description' => 'How many minutes back from the incident to summarise.',
                ],
            ],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function execute(Investigation $investigation, array $arguments): ToolResult
    {
        $incident = $investigation->incident;
        $minutes = (int) ($arguments['window_minutes'] ?? 30);

        $from = $incident->window_end->copy()->subMinutes($minutes);

        $stats = DB::table('health_checks')
            ->selectRaw("
                count(*)                                                       as samples,
                round(percentile_cont(0.95) within group (order by latency_ms)) as p95,
                round(percentile_cont(0.50) within group (order by latency_ms)) as p50,
                min(latency_ms)                                                as min_ms,
                max(latency_ms)                                                as max_ms,
                round(avg(case when is_error then 1.0 else 0.0 end) * 100)     as error_pct
            ")
            ->where('monitored_endpoint_id', $incident->monitored_endpoint_id)
            ->whereBetween('checked_at', [$from, $incident->window_end])
            ->first();

        $payload = [
            'service' => $incident->monitoredEndpoint?->service,
            'window_minutes' => $minutes,
            'samples' => (int) $stats->samples,
            'p50_ms' => (int) $stats->p50,
            'p95_ms' => (int) $stats->p95,
            'min_ms' => (int) $stats->min_ms,
            'max_ms' => (int) $stats->max_ms,
            'error_pct' => (int) $stats->error_pct,
        ];

        return new ToolResult(
            summary: sprintf(
                'Latency over the last %d min: p50 %dms, p95 %dms, %d%% errors across %d samples',
                $minutes, $payload['p50_ms'], $payload['p95_ms'], $payload['error_pct'], $payload['samples'],
            ),
            payload: $payload,
            source: 'healthz',
            kind: 'metric_series',
            sourceRef: "window:{$minutes}m",
        );
    }
}
