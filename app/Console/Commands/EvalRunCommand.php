<?php

namespace App\Console\Commands;

use App\Chaos\ScenarioLibrary;
use App\Agent\JanClient;
use App\Evaluation\EvaluationHarness;
use App\Models\EvaluationRun;
use App\Models\EvaluationScore;
use Illuminate\Console\Command;

class EvalRunCommand extends Command
{
    protected $signature = 'eval:run
                            {--scenario=* : Limit to specific scenario keys}
                            {--label= : Name for this run}
                            {--model=* : Synthesis model(s). Repeat the flag to compare models on identical scenarios.}
                            {--ablate=* : Guardrails to disable, for measuring their contribution: no-correlator, no-validator}';

    protected $description = 'Score the agent against seeded incidents with known root causes';

    public function handle(EvaluationHarness $harness): int
    {
        /*
        | Refuse to score against an unreachable model.
        |
        | Without this the harness happily records a zero for every scenario and
        | reports it as a hit rate, so an outage is indistinguishable from a bad
        | model -- and the misleading number lands in the run history where it
        | will later be compared against real ones.
        */
        if (! app(JanClient::class)->isReachable()) {
            $this->error('Jan is not reachable at '.config('agent.jan.base_url'));
            $this->newLine();
            $this->line('  Enable the local API server in Jan, then re-run. Nothing has been recorded.');

            return self::FAILURE;
        }

        $models = $this->option('model') ?: [config('agent.models.synthesis')];
        $scenarios = $this->option('scenario') ?: ScenarioLibrary::keys();

        // An empty ablation list still runs one pass with everything enabled.
        $ablations = $this->option('ablate') ?: [null];
        $runs = [];

        foreach ($models as $model) {
            foreach ($ablations as $ablation) {
                $runs[] = $this->runOne($harness, $scenarios, $model, $ablation);
            }
        }

        if (count($runs) > 1) {
            $this->renderComparison($runs);
        }

        return self::SUCCESS;
    }

    private function runOne(EvaluationHarness $harness, array $scenarios, string $model, ?string $ablation): EvaluationRun
    {
        {
            // Each pass faces the same scenario set, regenerated from scratch, so a
            // comparison is not confounded by different seeded data.
            config([
                'agent.models.synthesis' => $model,
                'agent.ablation' => $ablation,
            ]);

            $variant = $ablation ? "{$model} [{$ablation}]" : $model;

            $this->info('Evaluating '.count($scenarios).' scenario(s) on '.$variant);

            if ($ablation) {
                $this->comment('  guardrail disabled: '.$ablation);
            }

            $this->newLine();

            $label = trim(($this->option('label') ?: 'eval').($ablation ? " [{$ablation}]" : ''));

            $run = $harness->run($scenarios, $label, function (string $key, EvaluationScore $score) {
                $this->line(sprintf(
                    '  %-18s %s  confidence %.2f  cited %2d  fabricated %s  grounding %.2f',
                    $key,
                    $score->root_cause_hit ? '<fg=green>HIT </>' : '<fg=red>MISS</>',
                    $score->top_confidence ?? 0,
                    $score->cited_count,
                    $score->fabricated_count > 0
                        ? "<fg=red>{$score->fabricated_count}</>"
                        : '<fg=green>0</>',
                    $score->grounding_rate ?? 0,
                ));
            });

            $this->renderSummary($run);

            // Never let an ablation leak into a later run or the running app.
            config(['agent.ablation' => null]);

            return $run;
        }
    }

    /** @param  array<int,EvaluationRun>  $runs */
    private function renderComparison(array $runs): void
    {
        $this->line('<options=bold>Model comparison</> — identical scenarios');

        $this->table(
            ['run', 'root cause', 'evidence real', 'grounding', 'Brier'],
            array_map(fn (EvaluationRun $r) => [
                // The label carries the ablation; the model alone would print the
                // same name on every row of an ablation comparison.
                $r->label,
                $this->pct($r->root_cause_hit_rate),
                $this->pct($r->evidence_real_rate),
                $this->pct($r->grounding_rate),
                number_format((float) $r->brier_score, 3),
            ], $runs),
        );

        $this->line('  Read the evidence-real column first: where it drops, invented citations');
        $this->line('  reached the output. That is what the validator is preventing.');
        $this->newLine();
    }

    private function renderSummary(EvaluationRun $run): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$run->label.'</> — '.$run->model);

        $this->table(['metric', 'score', 'reading'], [
            ['root cause hit rate', $this->pct($run->root_cause_hit_rate),
                'leading hypothesis named the real deploy'],
            ['evidence real rate', $this->pct($run->evidence_real_rate),
                'citations that refer to evidence actually gathered'],
            ['grounding rate', $this->pct($run->grounding_rate),
                'figures traceable to the cited evidence'],
            ['Brier score', number_format((float) $run->brier_score, 3),
                'confidence calibration — lower is better, 0 is perfect'],
            ['action completeness', $this->pct($run->action_completeness),
                'Slack, Jira and Notion all staged'],
        ]);

        $this->line('  duration '.$run->started_at->diffInSeconds($run->finished_at).'s'
            .' across '.$run->scenario_count.' scenarios');
        $this->newLine();
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 1).'%';
    }
}
