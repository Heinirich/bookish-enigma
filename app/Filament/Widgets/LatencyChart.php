<?php

namespace App\Filament\Widgets;

use App\Models\Deployment;
use App\Models\MonitoredEndpoint;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * p95 latency per minute with deploy markers, which is the whole incident story
 * in one picture: the line steps up, and a marker sits at the step.
 */
class LatencyChart extends ChartWidget
{
    protected ?string $heading = 'Payments API — p95 latency';

    // Render with the page rather than deferring: the chart is the first thing
    // anyone looks at, and a skeleton placeholder is a poor opening frame.
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $endpoint = MonitoredEndpoint::where('is_active', true)->first();

        if (! $endpoint) {
            return ['datasets' => [], 'labels' => []];
        }

        $since = now()->subMinutes(45);

        $rows = DB::table('health_checks')
            ->selectRaw("
                date_trunc('minute', checked_at)                                as bucket,
                round(percentile_cont(0.95) within group (order by latency_ms))  as p95,
                round(avg(case when is_error then 1.0 else 0.0 end) * 100)      as error_pct
            ")
            ->where('monitored_endpoint_id', $endpoint->id)
            ->where('checked_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $deployMinutes = Deployment::where('deployed_at', '>=', $since)
            ->pluck('deployed_at')
            ->map(fn ($d) => $d->format('H:i'))
            ->all();

        $labels = $rows->map(fn ($r) => \Illuminate\Support\Carbon::parse($r->bucket)->format('H:i'))->all();

        return [
            'datasets' => [
                [
                    'label' => 'p95 (ms)',
                    'data' => $rows->pluck('p95')->map(fn ($v) => (int) $v)->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
                [
                    'label' => 'errors (%)',
                    'data' => $rows->pluck('error_pct')->map(fn ($v) => (int) $v)->all(),
                    'borderColor' => '#ef4444',
                    'borderDash' => [4, 4],
                    'fill' => false,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'yAxisID' => 'yErrors',
                ],
                [
                    // Spikes to the top of the chart on any minute that had a deploy.
                    'label' => 'deploy',
                    'data' => collect($labels)
                        ->map(fn ($l) => in_array($l, $deployMinutes, true) ? $rows->max('p95') : null)
                        ->all(),
                    'borderColor' => '#6366f1',
                    'backgroundColor' => '#6366f1',
                    'showLine' => false,
                    'pointRadius' => 5,
                    'pointStyle' => 'triangle',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'ms']],
                'yErrors' => [
                    'position' => 'right',
                    'beginAtZero' => true, 'max' => 100,
                    'grid' => ['drawOnChartArea' => false],
                    'title' => ['display' => true, 'text' => '%'],
                ],
            ],
            'plugins' => ['legend' => ['display' => true]],
            'maintainAspectRatio' => false,
        ];
    }
}
