<?php

namespace App\Evaluation;

use App\Agent\Investigator;
use App\Chaos\ScenarioActivator;
use App\Chaos\ScenarioLibrary;
use App\Models\AgentAction;
use App\Models\Commit;
use App\Models\Deployment;
use App\Models\EvaluationRun;
use App\Models\EvaluationScore;
use App\Models\HealthCheck;
use App\Models\Hypothesis;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\MonitoredEndpoint;
use App\Monitoring\IncidentDetector;
use App\Monitoring\SyntheticHistory;
use Illuminate\Support\Str;

/**
 * Scores the agent against incidents whose cause is known.
 *
 * The ground truth is a by-product of how the demo already works: activating a
 * scenario writes the culprit deploy and the degradation in one act, so nothing
 * has to be labelled by hand and the agent is measured on exactly the machinery
 * it will be demonstrated on.
 *
 * Four things are measured, because "did it get the right answer" alone would
 * reward a confident guesser:
 *
 *   root cause hit   did the leading hypothesis name the real deploy
 *   evidence real    were the citations genuine
 *   grounding        were the figures traceable to the cited evidence
 *   calibration      did stated confidence track actual correctness (Brier)
 */
class EvaluationHarness
{
    public function __construct(
        private readonly ScenarioActivator $activator,
        private readonly SyntheticHistory $history,
        private readonly IncidentDetector $detector,
        private readonly Investigator $investigator,
    ) {}

    /** @param  array<int,string>|null  $scenarioKeys */
    public function run(?array $scenarioKeys = null, ?string $label = null, ?callable $onProgress = null): EvaluationRun
    {
        $scenarioKeys ??= ScenarioLibrary::keys();

        $run = EvaluationRun::create([
            'label' => $label ?: 'eval '.now()->format('M j H:i'),
            'model' => config('agent.models.synthesis'),
            'started_at' => now(),
            'scenario_count' => count($scenarioKeys),
        ]);

        foreach ($scenarioKeys as $key) {
            $score = $this->runScenario($run, $key);
            $onProgress && $onProgress($key, $score);
        }

        return $this->summarise($run);
    }

    private function runScenario(EvaluationRun $run, string $scenarioKey): EvaluationScore
    {
        $this->reset();

        $endpoint = MonitoredEndpoint::where('is_active', true)->firstOrFail();

        $deployment = $this->activator->activate($scenarioKey);
        $this->history->generate($endpoint, $scenarioKey, now());

        // Ground truth is null when the scenario declares that no deploy is to blame.
        $expectsNoCause = ScenarioLibrary::get($scenarioKey)['expects_no_deploy_cause'] ?? false;
        $expectedSha = $expectsNoCause ? null : $deployment->sha;

        $incident = $this->detector->detect($endpoint, now());

        if ($incident === null) {
            return EvaluationScore::create([
                'evaluation_run_id' => $run->id,
                'scenario_key' => $scenarioKey,
                'expected_sha' => $expectedSha,
                'notes' => 'Detector did not open an incident.',
            ]);
        }

        $incident->update(['scenario_key' => $scenarioKey, 'trigger' => 'eval']);

        $investigation = $this->investigator->run(Investigator::open($incident));

        return $this->score($run, $scenarioKey, $expectedSha, $investigation);
    }

    private function score(
        EvaluationRun $run,
        string $scenarioKey,
        ?string $expectedSha,
        Investigation $investigation,
    ): EvaluationScore {
        $investigation->load(['hypotheses', 'actions']);
        $hypotheses = $investigation->hypotheses;

        // A crashed run is not a wrong answer. Recording it as a miss would let
        // infrastructure failures quietly depress a model's measured accuracy.
        if ($investigation->status === 'failed') {
            return EvaluationScore::create([
                'evaluation_run_id' => $run->id,
                'investigation_id' => $investigation->id,
                'scenario_key' => $scenarioKey,
                'expected_sha' => $expectedSha,
                'notes' => 'Run failed, not scored: '.Str::limit((string) $investigation->failure_reason, 120),
            ]);
        }

        // Scoring follows the same rule as the report: a ruled-out candidate is
        // not a finding, so it can never be the predicted root cause.
        $lead = $hypotheses
            ->where('status', 'accepted')
            ->where('stance', 'cause')
            ->sortByDesc('confidence')
            ->first();

        /*
        | When no deploy is to blame, a hit means the agent implicated none --
        | either by producing no accepted hypothesis, or by leaving root_cause_sha
        | null. Blaming the nearest deploy anyway is exactly the failure this
        | scenario exists to expose.
        */
        $hit = $expectedSha === null
            ? ($lead === null || $lead->root_cause_sha === null)
            : ($lead !== null && $lead->root_cause_sha === $expectedSha);

        $confidence = (float) ($lead->confidence ?? 0.0);

        $cited = $hypotheses->sum(fn (Hypothesis $h) => count($h->validation_report['cited'] ?? []));
        $fabricated = $hypotheses->sum(fn (Hypothesis $h) => count($h->validation_report['fabricated'] ?? []));

        $groundingScores = $hypotheses->pluck('grounding_score')->filter(fn ($v) => $v !== null);

        $actions = $investigation->actions
            ->where('was_write', true)
            ->mapWithKeys(fn (AgentAction $a) => [$a->tool => $a->status])
            ->all();

        return EvaluationScore::create([
            'evaluation_run_id' => $run->id,
            'investigation_id' => $investigation->id,
            'scenario_key' => $scenarioKey,
            'root_cause_hit' => $hit,
            'expected_sha' => $expectedSha,
            'predicted_sha' => $lead?->root_cause_sha,
            'top_confidence' => $confidence,
            'cited_count' => $cited,
            'fabricated_count' => $fabricated,
            'grounding_rate' => $groundingScores->isEmpty() ? null : round($groundingScores->avg(), 3),
            // Brier: squared error between stated confidence and the truth. A
            // confident wrong answer is punished far harder than a hedged one.
            'brier' => round(pow($confidence - ($hit ? 1.0 : 0.0), 2), 3),
            'actions_completed' => $actions,
            'notes' => $investigation->status === 'failed' ? $investigation->failure_reason : null,
        ]);
    }

    private function summarise(EvaluationRun $run): EvaluationRun
    {
        $all = $run->scores()->get();

        // Only genuinely unscored rows are excluded. Testing investigation_id here
        // was wrong: it also dropped rows whose investigation had been cleaned up,
        // which is why a seven-scenario run reported one.
        $scores = $all->filter(fn (EvaluationScore $s) => $s->notes === null);
        $count = max(1, $scores->count());

        $totalCited = $scores->sum('cited_count');
        $totalFabricated = $scores->sum('fabricated_count');
        $grounding = $scores->pluck('grounding_rate')->filter(fn ($v) => $v !== null);

        if ($scores->isEmpty()) {
            $run->update(['finished_at' => now(), 'scenario_count' => $all->count()]);

            return $run->fresh();
        }

        $run->update([
            'finished_at' => now(),
            'scenario_count' => $scores->count(),
            'root_cause_hit_rate' => round($scores->where('root_cause_hit', true)->count() / $count, 3),
            'evidence_real_rate' => $totalCited === 0
                ? 1.0
                : round(($totalCited - $totalFabricated) / $totalCited, 3),
            'grounding_rate' => $grounding->isEmpty() ? null : round($grounding->avg(), 3),
            'brier_score' => round((float) $scores->avg('brier'), 3),
            'action_completeness' => round($scores->avg(
                fn (EvaluationScore $s) => count($s->actions_completed ?? []) >= 3 ? 1 : 0,
            ) ?? 0, 3),
        ]);

        return $run->fresh();
    }

    /**
     * Give the next scenario a clean slate without destroying the last one's record.
     *
     * Deleting incidents cascaded to their investigations, so every scenario but
     * the final one lost its evidence, hypotheses and action log -- an eval result
     * that could not be audited afterwards, which defeats the point of an audit
     * trail. Prior incidents are closed instead: that clears the detector's
     * cooldown just as effectively, and the record survives.
     */
    private function reset(): void
    {
        Incident::whereIn('status', ['open', 'investigating'])->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => 'eval-harness',
            'resolution_note' => 'Closed automatically when the next evaluation scenario was staged.',
        ]);

        // Samples and deploy history must go, or the next scenario correlates
        // against the previous one's timeline.
        HealthCheck::query()->delete();
        Deployment::query()->delete();
        Commit::query()->delete();
    }
}
