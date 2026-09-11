<?php

namespace App\Filament\Widgets;

use App\Models\EvaluationRun;
use Filament\Widgets\ChartWidget;

/**
 * Recent evaluation runs side by side.
 *
 * Most useful for ablations: the same model and the same scenarios with a
 * guardrail switched off, so the bars show what that guardrail is actually
 * contributing rather than what it is assumed to contribute.
 */
class EvalComparison extends ChartWidget
{
    protected ?string $heading = 'Run comparison';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return EvaluationRun::whereNotNull('finished_at')->exists();
    }

    protected function getData(): array
    {
        /*
        | Grouped by label, not by model. With every remote model in Jan retired,
        | the comparison that actually matters is between ablations of the same
        | model -- guardrail on versus guardrail off -- and those share a model.
        */
        $runs = EvaluationRun::whereNotNull('finished_at')
            ->orderByDesc('started_at')
            ->get()
            ->unique('label')
            ->take(5)
            ->sortBy('started_at')
            ->values();

        if ($runs->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $pct = fn (?float $v) => $v === null ? 0 : round($v * 100, 1);

        return [
            'labels' => $runs->map(fn (EvaluationRun $r) => \Illuminate\Support\Str::limit($r->label, 34))->all(),
            'datasets' => [
                [
                    'label' => 'Root cause hit %',
                    'data' => $runs->map(fn ($r) => $pct($r->root_cause_hit_rate))->all(),
                    'backgroundColor' => '#6366f1',
                ],
                [
                    'label' => 'Evidence real %',
                    'data' => $runs->map(fn ($r) => $pct($r->evidence_real_rate))->all(),
                    'backgroundColor' => '#16a34a',
                ],
                [
                    'label' => 'Grounding %',
                    'data' => $runs->map(fn ($r) => $pct($r->grounding_rate))->all(),
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    // Scaled onto the same axis so calibration is comparable at a
                    // glance; the label keeps the direction honest.
                    'label' => 'Brier ×100 (lower better)',
                    'data' => $runs->map(fn ($r) => round((float) $r->brier_score * 100, 1))->all(),
                    'backgroundColor' => '#94a3b8',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'max' => 100]],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'maintainAspectRatio' => false,
        ];
    }
}
