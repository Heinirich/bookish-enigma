<?php

namespace App\Filament\Widgets;

use App\Agent\Correlator;
use App\Models\Deployment;
use App\Models\Incident;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * The correlation argument as a single picture: latency across the incident
 * window, each deploy marked where it landed, and the detected changepoint.
 *
 * A table of correlation scores states the conclusion; this shows the thing the
 * conclusion is drawn from, which is what someone checking the agent's work
 * actually needs.
 */
class IncidentTimeline extends ChartWidget
{
    public ?Incident $record = null;

    protected ?string $heading = 'Timeline';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $changepoint = $this->changepoint();

        if ($changepoint === null) {
            return 'No clear changepoint in this window.';
        }

        return sprintf(
            'p95 stepped from %dms to %dms (%s×) at %s. Deploy markers show what shipped nearby.',
            $changepoint['p95_before_ms'],
            $changepoint['p95_after_ms'],
            $changepoint['latency_ratio'],
            Carbon::parse($changepoint['at'])->format('H:i:s'),
        );
    }

    private function changepoint(): ?array
    {
        if (! $this->record) {
            return null;
        }

        $correlator = app(Correlator::class);

        return $correlator->changepoint($this->record, $correlator->series($this->record));
    }

    protected function getData(): array
    {
        if (! $this->record) {
            return ['datasets' => [], 'labels' => []];
        }

        $series = app(Correlator::class)->series($this->record);

        if ($series === []) {
            return ['datasets' => [], 'labels' => []];
        }

        $labels = array_map(fn ($b) => Carbon::parse($b['at'])->format('H:i'), $series);
        $p95 = array_map(fn ($b) => $b['p95_ms'], $series);
        $peak = max($p95) ?: 1;

        $changepoint = $this->changepoint();
        $changeLabel = $changepoint ? Carbon::parse($changepoint['at'])->format('H:i') : null;

        $deployMinutes = Deployment::whereBetween('deployed_at', [
            $this->record->window_start, $this->record->window_end,
        ])->pluck('deployed_at')->map(fn ($d) => $d->format('H:i'))->all();

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'p95 (ms)',
                    'data' => $p95,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'order' => 3,
                ],
                [
                    'label' => 'errors (%)',
                    'data' => array_map(fn ($b) => $b['error_pct'], $series),
                    'borderColor' => '#ef4444',
                    'borderDash' => [4, 4],
                    'fill' => false,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'yAxisID' => 'yErrors',
                    'order' => 2,
                ],
                [
                    // Vertical spikes rather than a line: these are events, not a series.
                    'label' => 'deploy',
                    'data' => array_map(
                        fn (string $l) => in_array($l, $deployMinutes, true) ? $peak : null,
                        $labels,
                    ),
                    'borderColor' => '#6366f1',
                    'backgroundColor' => '#6366f1',
                    'showLine' => false,
                    'pointRadius' => 6,
                    'pointStyle' => 'triangle',
                    'order' => 1,
                ],
                [
                    'label' => 'changepoint',
                    'data' => array_map(
                        fn (string $l) => $changeLabel !== null && $l === $changeLabel ? $peak : null,
                        $labels,
                    ),
                    'borderColor' => '#dc2626',
                    'backgroundColor' => '#dc2626',
                    'showLine' => false,
                    'pointRadius' => 8,
                    'pointStyle' => 'crossRot',
                    'order' => 0,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'ms']],
                'yErrors' => [
                    'position' => 'right', 'beginAtZero' => true, 'max' => 100,
                    'grid' => ['drawOnChartArea' => false],
                    'title' => ['display' => true, 'text' => '%'],
                ],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'maintainAspectRatio' => false,
        ];
    }
}
