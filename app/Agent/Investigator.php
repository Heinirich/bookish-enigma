<?php

namespace App\Agent;

use App\Agent\Schemas\HypothesisSchema;
use App\Agent\Tools\AgentTool;
use App\Agent\Tools\ToolResult;
use App\Connectors\Contracts\GitHubConnector;
use App\Models\AgentAction;
use App\Models\Deployment;
use App\Models\Hypothesis;
use App\Models\Incident;
use App\Models\Investigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs an incident to a documented conclusion.
 *
 * Four phases, in deliberate order:
 *
 *   gather      deterministic. Metrics, deployments and correlation candidates are
 *               computed in PHP and written to the ledger. Evidence therefore exists
 *               before the model is consulted, and exists even if the model fails.
 *   explore     the model may pull additional facts through read-only tools. Every
 *               call is logged and everything it returns joins the ledger.
 *   synthesize  hypotheses under a strict schema, then validated against the ledger.
 *   act         write-ups and tickets, held for approval.
 */
class Investigator
{
    /** Below this correlation score, no deployment is a credible explanation. */
    private const WEAK_CORRELATION_CEILING = 0.45;

    private int $sequence = 0;

    public function __construct(
        private readonly JanClient $jan,
        private readonly EvidenceLedger $ledger,
        private readonly EvidenceValidator $validator,
        private readonly Correlator $correlator,
        private readonly ToolRegistry $tools,
        private readonly ActionExecutor $actions,
        private readonly GitHubConnector $github,
    ) {}

    public function run(Investigation $investigation): Investigation
    {
        $investigation->update(['status' => 'gathering', 'started_at' => now()]);
        $startedAt = microtime(true);
        $this->sequence = (int) AgentAction::where('investigation_id', $investigation->id)->max('sequence');

        try {
            $changepoint = $this->gather($investigation);

            $investigation->update(['status' => 'exploring']);
            $this->explore($investigation);

            $investigation->update(['status' => 'synthesizing']);
            $this->synthesize($investigation, $changepoint);

            $investigation->update(['status' => 'acting']);
            $this->actions->plan($investigation);

            $investigation->update([
                'status' => 'completed',
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            Log::error('Investigation failed', [
                'investigation' => $investigation->id,
                'error' => $e->getMessage(),
            ]);

            $investigation->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        /*
        | The record can vanish mid-run: resetting demo data or an eval scenario
        | deletes incidents, which cascades to investigations. Returning the
        | in-memory model rather than null keeps a torn-down run from surfacing
        | as a TypeError in the queue worker.
        */
        return $investigation->fresh() ?? $investigation;
    }

    // ---------------------------------------------------------------- gather

    /** @return array<string,mixed>|null the detected changepoint, if any */
    private function gather(Investigation $investigation): ?array
    {
        $incident = $investigation->incident;

        $this->syncDeployments($investigation, $incident);

        $series = $this->timed($investigation, 'compute_latency_series', 'gather', [], function () use ($investigation, $incident) {
            $series = $this->correlator->series($incident);

            $this->ledger->record(
                $investigation,
                source: 'healthz',
                kind: 'metric_series',
                summary: sprintf(
                    'Per-minute latency for %s across the incident window (%d buckets)',
                    $incident->monitoredEndpoint?->service ?? 'service',
                    count($series),
                ),
                payload: [
                    'window_start' => $incident->window_start->toIso8601String(),
                    'window_end' => $incident->window_end->toIso8601String(),
                    'buckets' => $this->condenseSeries($series),
                ],
                sourceRef: 'healthz:series',
            );

            return $series;
        });

        $changepoint = $this->timed($investigation, 'detect_changepoint', 'gather', [], function () use ($investigation, $series, $incident) {
            $changepoint = $this->correlator->changepoint($incident, $series);

            if ($changepoint === null || config('agent.ablation') === 'no-correlator') {
                return $changepoint;
            }

            $this->ledger->record(
                $investigation,
                source: 'correlator',
                kind: 'changepoint',
                summary: sprintf(
                    'Latency stepped from p95 %dms to p95 %dms (%sx) at %s',
                    $changepoint['p95_before_ms'],
                    $changepoint['p95_after_ms'],
                    $changepoint['latency_ratio'],
                    Carbon::parse($changepoint['at'])->toTimeString(),
                ),
                payload: $changepoint,
                sourceRef: 'correlator:changepoint',
            );

            return $changepoint;
        });

        $this->timed($investigation, 'score_deployments', 'gather', [], function () use ($investigation, $incident, $changepoint) {
            $pivot = $changepoint ? Carbon::parse($changepoint['at']) : null;
            $candidates = $this->correlator->candidates($incident, $pivot);

            // Ablation: hand over the deployments with no timing analysis and no
            // ranking, so the run measures the model correlating unaided.
            if (config('agent.ablation') === 'no-correlator') {
                foreach ($candidates as $candidate) {
                    $this->ledger->record(
                        $investigation,
                        source: 'github',
                        kind: 'deployment',
                        summary: sprintf('Deploy %s "%s" at %s',
                            $candidate['sha'],
                            Str::limit((string) $candidate['message'], 60),
                            $candidate['deployed_at'],
                        ),
                        payload: [
                            'sha' => $candidate['sha'],
                            'message' => $candidate['message'],
                            'author' => $candidate['author'],
                            'deployed_at' => $candidate['deployed_at'],
                            'changed_files' => $candidate['changed_files'],
                            'files_changed' => $candidate['files_changed'],
                        ],
                        sourceRef: $candidate['sha'],
                    );
                }

                return $candidates;
            }

            /*
            | Absence of a cause has to be representable as evidence. Without an
            | explicit fact saying "nothing deployed close enough to explain this",
            | the model sees only a ranked list and reads the top row as the answer,
            | which is how it blamed an unrelated deploy on the no-cause scenario.
            */
            $topScore = $candidates[0]['correlation_score'] ?? 0.0;

            if ($candidates === [] || $topScore < self::WEAK_CORRELATION_CEILING) {
                $this->ledger->record(
                    $investigation,
                    source: 'correlator',
                    kind: 'changepoint',
                    summary: $candidates === []
                        ? 'No deployment shipped anywhere near the changepoint'
                        : sprintf(
                            'No deployment correlates strongly with the changepoint (best score %.2f, below the %.2f threshold)',
                            $topScore,
                            self::WEAK_CORRELATION_CEILING,
                        ),
                    payload: [
                        'best_correlation_score' => $topScore,
                        'threshold' => self::WEAK_CORRELATION_CEILING,
                        'candidate_count' => count($candidates),
                        'interpretation' => 'The timing does not implicate any deployment. '
                            .'A cause outside the codebase (infrastructure, traffic, an upstream '
                            .'dependency) is more consistent with this evidence.',
                    ],
                    sourceRef: 'correlator:no-strong-candidate',
                );
            }

            foreach ($candidates as $candidate) {
                $this->ledger->record(
                    $investigation,
                    source: 'correlator',
                    kind: 'deployment',
                    summary: sprintf(
                        'Deploy %s "%s" shipped %s min before the changepoint (correlation %.2f)',
                        $candidate['sha'],
                        Str::limit((string) $candidate['message'], 60),
                        $candidate['minutes_before_changepoint'],
                        $candidate['correlation_score'],
                    ),
                    payload: $candidate,
                    sourceRef: $candidate['sha'],
                );
            }

            return $candidates;
        });

        return $changepoint;
    }

    /**
     * Pull real deployment history for the window before anything is correlated.
     *
     * The correlator reads deployments from local storage, so without this step a
     * live GitHub connector contributes nothing and the agent only ever sees
     * whatever was seeded -- the integration exists but never runs.
     *
     * Deliberately non-fatal: GitHub being unreachable should degrade the
     * evidence available, not abort the investigation. The action log records
     * that it failed so a thin result is explainable afterwards.
     */
    private function syncDeployments(Investigation $investigation, Incident $incident): void
    {
        $config = config('agent.correlation');

        $from = $incident->window_start->copy()->subMinutes($config['lookback_minutes']);
        $to = $incident->window_end->copy()->addMinutes($config['lookahead_minutes']);

        $startedAt = microtime(true);

        try {
            $synced = $this->github->sync($from, $to);

            $this->recordAction(
                $investigation, 'sync_deployments', 'gather',
                ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                ['synced' => $synced],
                summary: $synced > 0
                    ? "Pulled {$synced} deployment(s) from GitHub for the incident window"
                    : 'No new deployments to pull from GitHub',
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        } catch (\Throwable $e) {
            $this->recordAction(
                $investigation, 'sync_deployments', 'gather', [], null,
                status: 'failed', error: $e->getMessage(),
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }
    }

    // --------------------------------------------------------------- explore

    private function explore(Investigation $investigation): void
    {
        $maxIterations = (int) config('agent.max_tool_iterations');
        $tools = $this->tools->readOnly();

        $messages = [
            ['role' => 'system', 'content' => $this->exploreSystemPrompt()],
            ['role' => 'user', 'content' => $this->incidentBrief($investigation)],
        ];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $response = $this->jan->chat(
                config('agent.models.explore'),
                $messages,
                [
                    'tools' => $this->tools->toOpenAiSchema($tools),
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                ],
            );

            $this->accrueTokens($investigation, $response);

            if (! $response->hasToolCalls()) {
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => array_map(fn (ToolCall $c) => $c->toAssistantPayload(), $response->toolCalls),
            ];

            foreach ($response->toolCalls as $call) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'content' => $this->runTool($investigation, $call),
                ];
            }

            $investigation->increment('tool_iterations');
        }
    }

    private function runTool(Investigation $investigation, ToolCall $call): string
    {
        $tool = $this->tools->get($call->name);

        if ($tool === null) {
            $this->recordAction($investigation, $call->name, 'explore', $call->arguments, null,
                status: 'failed', error: 'Unknown tool');

            return "Error: no tool named '{$call->name}'.";
        }

        // The model is only ever offered read-only tools during exploration;
        // a write reaching here would mean the registry and the prompt disagree.
        if ($tool->isWrite()) {
            $this->recordAction($investigation, $call->name, 'explore', $call->arguments, null,
                status: 'rejected', error: 'Write tools are not available during exploration');

            return "Error: '{$call->name}' is not available in this phase.";
        }

        try {
            $started = microtime(true);
            $result = $tool->execute($investigation, $call->arguments);
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            $evidenceId = null;

            if ($result->recordEvidence) {
                $evidence = $this->ledger->record(
                    $investigation,
                    source: $result->source,
                    kind: $result->kind,
                    summary: $result->summary,
                    payload: $result->payload,
                    sourceRef: $result->sourceRef,
                    sourceUrl: $result->sourceUrl,
                );
                $evidenceId = $evidence->public_id;
            }

            $this->recordAction(
                $investigation, $call->name, 'explore', $call->arguments,
                $result->payload, summary: $result->summary, durationMs: $durationMs,
            );

            // Handing back the evidence ID teaches the model, in-context, that
            // citations are assigned by the ledger rather than chosen by it.
            return $evidenceId
                ? "[{$evidenceId}] {$result->summary}\n".json_encode($result->payload)
                : $result->summary;
        } catch (\Throwable $e) {
            $this->recordAction($investigation, $call->name, 'explore', $call->arguments, null,
                status: 'failed', error: $e->getMessage());

            return "Error running {$call->name}: {$e->getMessage()}";
        }
    }

    // ------------------------------------------------------------ synthesize

    private function synthesize(Investigation $investigation, ?array $changepoint): void
    {
        $validIds = $this->ledger->publicIds($investigation);

        $messages = [
            ['role' => 'system', 'content' => $this->synthesisSystemPrompt($validIds)],
            ['role' => 'user', 'content' => $this->synthesisPrompt($investigation, $changepoint)],
        ];

        $response = $this->jan->chat(
            config('agent.models.synthesis'),
            $messages,
            [
                'response_format' => HypothesisSchema::responseFormat(),
                'temperature' => 0.3,
                'max_tokens' => 1200,
            ],
        );

        $this->accrueTokens($investigation, $response);
        $decoded = $response->json();

        $this->recordAction($investigation, 'synthesize_hypotheses', 'synthesize', [],
            $decoded, summary: $decoded['summary'] ?? null);

        $hypotheses = $decoded['hypotheses'] ?? [];

        // One repair turn. If the model cited evidence that does not exist, it is
        // told exactly which IDs were invented and given the real set again.
        $fabricatedAnywhere = collect($hypotheses)
            ->flatMap(fn ($h) => $this->validator->validate($investigation, $h)->fabricated)
            ->unique()
            ->values()
            ->all();

        $validatorDisabled = config('agent.ablation') === 'no-validator';

        if ($fabricatedAnywhere !== [] && ! $validatorDisabled) {
            $hypotheses = $this->repair($investigation, $messages, $response, $fabricatedAnywhere, $validIds)
                ?? $hypotheses;
        }

        $this->persistHypotheses($investigation, $hypotheses);
    }

    /** @return array<int,array<string,mixed>>|null */
    private function repair(
        Investigation $investigation,
        array $messages,
        ChatResponse $previous,
        array $fabricated,
        array $validIds,
    ): ?array {
        $messages[] = ['role' => 'assistant', 'content' => $previous->content];
        $messages[] = [
            'role' => 'user',
            'content' => "These evidence IDs do not exist: ".implode(', ', $fabricated)."\n\n"
                ."The only valid IDs are: ".implode(', ', $validIds)."\n\n"
                ."Re-emit the hypotheses citing only IDs from that list. If a claim cannot be "
                ."supported by any of them, drop the claim rather than inventing a reference.",
        ];

        try {
            $repaired = $this->jan->chat(
                config('agent.models.synthesis'),
                $messages,
                [
                    'response_format' => HypothesisSchema::responseFormat(),
                    'temperature' => 0.1,
                    'max_tokens' => 1200,
                ],
            );

            $this->accrueTokens($investigation, $repaired);
            $decoded = $repaired->json();

            $this->recordAction($investigation, 'repair_citations', 'synthesize',
                ['fabricated' => $fabricated], $decoded,
                summary: 'Re-generated after '.count($fabricated).' fabricated citation(s)');

            return $decoded['hypotheses'] ?? null;
        } catch (\Throwable $e) {
            $this->recordAction($investigation, 'repair_citations', 'synthesize',
                ['fabricated' => $fabricated], null, status: 'failed', error: $e->getMessage());

            return null;
        }
    }

    private function persistHypotheses(Investigation $investigation, array $hypotheses): void
    {
        $rank = 0;

        foreach ($hypotheses as $raw) {
            $report = $this->validator->validate($investigation, $raw);
            $rank++;

            $deployment = $this->resolveDeployment($raw['root_cause_sha'] ?? null);

            $hypothesis = Hypothesis::create([
                'investigation_id' => $investigation->id,
                'statement' => (string) ($raw['statement'] ?? ''),
                'mechanism' => trim((string) ($raw['mechanism'] ?? '')) ?: null,
                'remediation' => trim((string) ($raw['remediation'] ?? '')) ?: null,
                'stance' => in_array($raw['stance'] ?? 'cause', ['cause', 'ruled_out'], true)
                    ? $raw['stance']
                    : 'cause',
                // Stored as `confidence` for continuity; the model is asked for it
                // under an unambiguous name.
                'confidence' => $this->clampConfidence(
                    $raw['probability_this_explains_the_incident'] ?? $raw['confidence'] ?? 0,
                ),
                'rank' => $rank,
                'root_cause_deployment_id' => $deployment?->id,
                'root_cause_sha' => $deployment?->sha,
                // A claim resting on invented references is kept, not deleted --
                // a discarded claim is part of the audit trail, and showing it is
                // more honest than presenting a board that was quietly cleaned.
                // Under the no-validator ablation everything is accepted, which is
                // exactly the point: the eval then counts how many invented
                // citations would have reached Slack, Jira and Notion.
                'status' => (config('agent.ablation') === 'no-validator' || $report->passed())
                    ? 'accepted'
                    : 'rejected_unsupported',
                'grounding_score' => $report->groundingScore,
                'validation_report' => $report->toArray(),
                'raw_evidence_ids' => $raw['evidence_ids'] ?? [],
            ]);

            $this->attachEvidence($investigation, $hypothesis, $raw, $report);
        }
    }

    private function attachEvidence(
        Investigation $investigation,
        Hypothesis $hypothesis,
        array $raw,
        ValidationReport $report,
    ): void {
        $contradicting = array_map('trim', $raw['contradicting_evidence_ids'] ?? []);

        foreach ($report->valid as $publicId) {
            $evidence = $this->ledger->find($investigation, $publicId);

            if ($evidence === null) {
                continue;
            }

            $hypothesis->evidence()->syncWithoutDetaching([
                $evidence->id => [
                    'relation' => in_array($publicId, $contradicting, true) ? 'contradicts' : 'supports',
                ],
            ]);
        }
    }

    private function resolveDeployment(?string $sha): ?Deployment
    {
        if (! $sha) {
            return null;
        }

        $sha = trim($sha);

        return Deployment::where('sha', $sha)
            ->orWhere('sha', 'like', $sha.'%')
            ->first();
    }

    private function clampConfidence(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    // ---------------------------------------------------------------- prompts

    private function exploreSystemPrompt(): string
    {
        return <<<'TXT'
        You are an incident investigator for a payments API.

        You have already been given the core measurements. Your job in this phase is only
        to decide whether any ADDITIONAL facts would change the picture, and to fetch them
        with the tools provided.

        Guidance:
        - Call a tool only when it would tell you something you do not already know.
        - Read the diff of every deployment close to the changepoint, not only the most
          suspicious one. A harmless-sounding commit message can hide the defect, and a
          worrying one can turn out to touch nothing on the request path. The code decides.
        - When you have enough to explain the incident, reply with the single word DONE
          and make no further tool calls.

        Do not state a cause in this phase. You will be asked for conclusions separately.
        TXT;
    }

    private function synthesisSystemPrompt(array $validIds): string
    {
        $ids = implode(', ', $validIds);

        return <<<TXT
        You are an incident investigator. You produce structured findings, never prose verdicts.

        THE ONLY VALID EVIDENCE IDS ARE: {$ids}

        Rules, in order of importance:

        1. Cite only IDs from the list above, written exactly as shown. Never invent an ID,
           never guess one, never construct a descriptive identifier of your own. If a claim
           cannot be supported by an ID on that list, do not make the claim.
        2. Every figure in a statement must come from the evidence you cite. Do not estimate,
           extrapolate, or round beyond what the evidence shows.
        2b. A statement must explain the mechanism, not just name a deploy. Say what the changed
           code does that produces this symptom, and include a figure from the evidence.
           "Deploy abc1234 caused the latency spike" is not an acceptable statement — it asserts
           a conclusion without giving anyone a way to check it. Read the diff and describe what
           in it is responsible: a query added to a loop, a timeout lowered below the upstream's
           response time, a cache lifetime shortened, a renamed binding a call site still uses.
        3. Set root_cause_sha only to a SHA that appears in the evidence. Set it to null
           when no deployment plausibly explains the symptom -- an incident can be caused
           by infrastructure, traffic, or an upstream dependency rather than by a code
           change. A deployment being the closest in time is not on its own a reason to
           blame it; the diff must describe a mechanism that would produce this symptom.
           Naming a deploy you do not believe in is worse than naming none.
        4. Confidence must reflect the evidence. High correlation with a plausible mechanism
           in the diff warrants a high value; timing alone, with several candidates close
           together, does not.
        5. Prefer one well-supported hypothesis to three speculative ones.
        6. Set stance to "cause" only for what you believe explains the incident. Use
           "ruled_out" for a candidate you examined and are excluding.

           A ruled_out statement must READ as an exclusion and say what the change actually does
           instead. Write "Deploy abc1234 only reformats timestamps during serialisation and adds
           no queries or IO, so it does not explain the p95 rise to 1050ms."
           Never write "Deploy abc1234 caused the latency spike" and attach a low confidence —
           the sentence then contradicts the stance and is useless to whoever reads the report.
        TXT;
    }

    private function incidentBrief(Investigation $investigation): string
    {
        $incident = $investigation->incident;

        return "INCIDENT {$incident->reference}: {$incident->title}\n"
            ."Task: {$incident->prompt}\n"
            ."Window: {$incident->window_start->toDateTimeString()} to {$incident->window_end->toDateTimeString()} UTC\n\n"
            ."EVIDENCE ALREADY GATHERED:\n"
            .$this->ledger->renderContext($investigation);
    }

    private function synthesisPrompt(Investigation $investigation, ?array $changepoint): string
    {
        $incident = $investigation->incident;

        $changepointLine = $changepoint
            ? "A changepoint was detected at {$changepoint['at']}: p95 went from "
                ."{$changepoint['p95_before_ms']}ms to {$changepoint['p95_after_ms']}ms "
                ."({$changepoint['latency_ratio']}x)."
            : 'No clear changepoint was detected in the latency series.';

        return "INCIDENT {$incident->reference}: {$incident->title}\n"
            ."{$incident->prompt}\n\n"
            ."{$changepointLine}\n\n"
            ."EVIDENCE:\n"
            .$this->ledger->renderContext($investigation)
            ."\n\nProduce ranked hypotheses explaining this incident. Note that correlation_score "
            ."is a computed timing-and-relevance measure, not a verdict — weigh it alongside "
            ."whether the diff describes a mechanism that would actually produce this symptom.";
    }

    // --------------------------------------------------------------- plumbing

    private function condenseSeries(array $series): array
    {
        // Keep the context tight: a 4B model reads a 30-point digest far better
        // than 200 raw samples, and the full series stays in the database.
        if (count($series) <= 30) {
            return $series;
        }

        $step = (int) ceil(count($series) / 30);

        return array_values(array_filter(
            $series,
            fn ($_, $i) => $i % $step === 0 || $i === count($series) - 1,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function timed(Investigation $investigation, string $tool, string $phase, array $args, \Closure $callback): mixed
    {
        $started = microtime(true);
        $result = $callback();

        $this->recordAction(
            $investigation, $tool, $phase, $args,
            is_array($result) ? ['items' => count($result)] : null,
            durationMs: (int) round((microtime(true) - $started) * 1000),
        );

        return $result;
    }

    private function recordAction(
        Investigation $investigation,
        string $tool,
        string $phase,
        array $arguments,
        ?array $result,
        ?string $summary = null,
        string $status = 'succeeded',
        ?string $error = null,
        ?int $durationMs = null,
        bool $isWrite = false,
    ): AgentAction {
        return AgentAction::create([
            'investigation_id' => $investigation->id,
            'sequence' => ++$this->sequence,
            'tool' => $tool,
            'phase' => $phase,
            'arguments' => $arguments,
            'result' => $result,
            'result_summary' => $summary,
            'status' => $status,
            'error' => $error,
            'duration_ms' => $durationMs,
            'was_write' => $isWrite,
            'occurred_at' => now(),
        ]);
    }

    private function accrueTokens(Investigation $investigation, ChatResponse $response): void
    {
        $investigation->increment('prompt_tokens', $response->promptTokens);
        $investigation->increment('completion_tokens', $response->completionTokens);
    }

    public static function open(Incident $incident): Investigation
    {
        return Investigation::create([
            'incident_id' => $incident->id,
            'model' => config('agent.models.synthesis'),
            'status' => 'queued',
        ]);
    }
}
