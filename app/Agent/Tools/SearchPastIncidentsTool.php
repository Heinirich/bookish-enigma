<?php

namespace App\Agent\Tools;

use App\Models\Hypothesis;
use App\Models\Incident;
use App\Models\Investigation;
use Illuminate\Support\Str;

/**
 * Institutional memory: what happened last time something looked like this.
 *
 * Prior incidents that a human resolved are the only evidence in the system that
 * was verified against reality rather than inferred, so they are ranked above
 * everything else here. An outcome someone confirmed is worth more than ten the
 * agent merely concluded.
 */
class SearchPastIncidentsTool implements AgentTool
{
    /** Only look back this far; a year-old incident says little about today's stack. */
    private const LOOKBACK_DAYS = 90;

    public function name(): string
    {
        return 'search_past_incidents';
    }

    public function description(): string
    {
        return 'Search previous incidents on this service for ones with a similar symptom. '
            .'Returns what each was eventually found to be caused by, and whether a human '
            .'confirmed that conclusion. Use it to check whether this has happened before.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['symptom'],
            'properties' => [
                'symptom' => [
                    'type' => 'string',
                    'enum' => ['latency', 'errors', 'any'],
                    'description' => 'The kind of degradation to look for.',
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
        $symptom = $arguments['symptom'] ?? 'any';

        $past = Incident::query()
            ->where('id', '!=', $incident->id)
            ->where('monitored_endpoint_id', $incident->monitored_endpoint_id)
            ->where('detected_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->when($symptom === 'latency', fn ($q) => $q->where('detection_signal->reason', 'latency_p95'))
            ->when($symptom === 'errors', fn ($q) => $q->where('detection_signal->reason', 'error_rate'))
            // Resolved first: those carry an outcome somebody checked.
            ->orderByRaw('resolved_at is null')
            ->orderByDesc('detected_at')
            ->limit(5)
            ->get();

        if ($past->isEmpty()) {
            return ToolResult::empty(
                'No previous incidents on this service in the last '.self::LOOKBACK_DAYS.' days.',
                'incidents',
                'history',
            );
        }

        $entries = $past->map(function (Incident $prior) {
            $lead = Hypothesis::whereIn('investigation_id', $prior->investigations()->pluck('id'))
                ->where('stance', 'cause')
                ->where('status', 'accepted')
                ->orderByDesc('confidence')
                ->first();

            return array_filter([
                'reference' => $prior->reference,
                'detected_at' => $prior->detected_at->toIso8601String(),
                'title' => $prior->title,
                'severity' => $prior->severity,
                'latency_ratio' => $prior->detection_signal['latency_ratio'] ?? null,
                'resolved' => $prior->isResolved(),
                // The distinction the agent should weigh: a cause a person
                // confirmed versus one the agent proposed and nobody checked.
                'confirmed_cause_sha' => $prior->actual_cause_sha
                    ? substr($prior->actual_cause_sha, 0, 7)
                    : null,
                'resolution' => $prior->resolution_note,
                'agent_blamed_sha' => $lead?->root_cause_sha ? substr($lead->root_cause_sha, 0, 7) : null,
                'agent_conclusion' => $lead ? Str::limit($lead->statement, 140) : null,
                'human_verdict' => $lead?->verdict,
            ], fn ($v) => $v !== null && $v !== '');
        })->all();

        $confirmed = collect($entries)->whereNotNull('confirmed_cause_sha')->count();

        return new ToolResult(
            summary: sprintf(
                '%d previous incident(s) on this service in %d days, %d with a confirmed cause',
                count($entries), self::LOOKBACK_DAYS, $confirmed,
            ),
            payload: ['lookback_days' => self::LOOKBACK_DAYS, 'incidents' => $entries],
            source: 'incidents',
            kind: 'history',
            sourceRef: 'history:'.$symptom,
        );
    }
}
