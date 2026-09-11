<?php

namespace App\Reporting;

use App\Models\Evidence;
use App\Models\Hypothesis;
use App\Models\Investigation;
use Illuminate\Support\Str;

/**
 * Renders an investigation for the outside world.
 *
 * Written so that a reader who does not trust the agent can still audit it:
 * every hypothesis carries its evidence IDs, and the verification counts are
 * stated plainly, including claims the validator discarded. A report that hid
 * its rejects would defeat the point of computing them.
 */
class InvestigationReport
{
    public function __construct(private readonly Investigation $investigation) {}

    public static function for(Investigation $investigation): self
    {
        return new self($investigation->load(['incident', 'hypotheses.evidence', 'evidence', 'actions']));
    }

    public function title(): string
    {
        return "{$this->investigation->incident->reference}: {$this->investigation->incident->title}";
    }

    public function channelName(): string
    {
        $prefix = (string) config('connectors.slack.channel_prefix');
        $reference = Str::lower($this->investigation->incident->reference);

        // References already read "INC-XXXX"; blindly prepending the prefix
        // produced channels named inc-inc-xxxx.
        $name = Str::startsWith($reference, Str::lower(rtrim($prefix, '-')))
            ? $reference
            : $prefix.$reference;

        return Str::of($name)->slug()->limit(78, '')->value();
    }

    public function leadHypothesis(): ?Hypothesis
    {
        // An exclusion is never the finding, however confidently it is held.
        return $this->investigation->hypotheses
            ->where('status', 'accepted')
            ->where('stance', 'cause')
            ->sortByDesc('confidence')
            ->first();
    }

    public function verification(): array
    {
        $all = $this->investigation->hypotheses;

        return [
            'generated' => $all->count(),
            'accepted' => $all->where('status', 'accepted')->count(),
            'discarded' => $all->where('status', '!=', 'accepted')->count(),
            'citations' => $all->sum(fn (Hypothesis $h) => count($h->validation_report['cited'] ?? [])),
            'fabricated' => $all->sum(fn (Hypothesis $h) => count($h->validation_report['fabricated'] ?? [])),
            'evidence' => $this->investigation->evidence->count(),
        ];
    }

    /** Plain text for Slack. */
    public function slackSummary(): string
    {
        $incident = $this->investigation->incident;
        $lead = $this->leadHypothesis();
        $v = $this->verification();

        $lines = [
            "*{$this->title()}*",
            "Severity `{$incident->severity}`  •  detected {$incident->detected_at->toDayDateTimeString()} UTC",
            '',
        ];

        if ($lead) {
            $cited = $lead->evidence->pluck('public_id')->implode(', ');
            $lines[] = '*Leading hypothesis* ('.number_format($lead->confidence * 100).'% likely)';
            $lines[] = $lead->statement;

            if ($lead->mechanism) {
                $lines[] = $lead->mechanism;
            }

            if ($lead->remediation) {
                $lines[] = '';
                $lines[] = "*Suggested next step*  {$lead->remediation}";
            }

            $lines[] = "_Evidence: {$cited}_";
        } else {
            $lines[] = '*No hypothesis survived validation.* The evidence gathered did not support a conclusion.';
        }

        $lines[] = '';
        $lines[] = "*Verification*  {$v['accepted']} accepted, {$v['discarded']} discarded, "
            ."{$v['citations']} citations checked, *{$v['fabricated']} fabricated*, {$v['evidence']} evidence items on file.";

        if ($others = $this->otherHypotheses()) {
            $lines[] = '';
            $lines[] = '*Also considered*';
            foreach ($others as $h) {
                $lines[] = '• '.Str::limit($h->statement, 160).' ('.number_format($h->confidence * 100).'%)';
            }
        }

        return implode("\n", $lines);
    }

    /** Markdown for Jira and Notion. */
    public function markdown(): string
    {
        $incident = $this->investigation->incident;
        $v = $this->verification();
        $lead = $this->leadHypothesis();

        $md = [
            "## {$this->title()}",
            '',
            "- **Severity:** {$incident->severity}",
            "- **Detected:** {$incident->detected_at->toDayDateTimeString()} UTC",
            "- **Window:** {$incident->window_start->toTimeString()} – {$incident->window_end->toTimeString()} UTC",
            "- **Model:** {$this->investigation->model}",
            '',
            '### Findings',
            '',
        ];

        if ($this->investigation->hypotheses->isEmpty()) {
            $md[] = '_No hypotheses were produced._';
        }

        foreach ($this->investigation->hypotheses as $h) {
            $status = $h->status === 'accepted' ? 'Accepted' : 'Discarded — unsupported';
            $cited = $h->evidence->pluck('public_id')->implode(', ') ?: '—';

            $md[] = "**{$h->rank}. {$status}** · confidence "
                .number_format($h->confidence, 2).' · grounding '.number_format((float) $h->grounding_score, 2);
            $md[] = '';
            $md[] = $h->statement;

            if ($h->mechanism) {
                $md[] = '';
                $md[] = "_Mechanism:_ {$h->mechanism}";
            }

            if ($h->remediation && $h->stance === 'cause') {
                $md[] = '';
                $md[] = "_Suggested next step:_ {$h->remediation}";
            }

            $md[] = '';
            $md[] = "_Evidence: {$cited}_";

            if ($fabricated = ($h->validation_report['fabricated'] ?? [])) {
                $md[] = '_Rejected citations: '.implode(', ', $fabricated).'_';
            }

            $md[] = '';
        }

        $md[] = '### Verification';
        $md[] = '';
        $md[] = "| metric | value |";
        $md[] = "| --- | --- |";
        $md[] = "| claims generated | {$v['generated']} |";
        $md[] = "| accepted | {$v['accepted']} |";
        $md[] = "| discarded as unsupported | {$v['discarded']} |";
        $md[] = "| citations checked | {$v['citations']} |";
        $md[] = "| fabricated citations | {$v['fabricated']} |";
        $md[] = "| evidence items | {$v['evidence']} |";
        $md[] = '';

        $md[] = '### Evidence';
        $md[] = '';
        foreach ($this->investigation->evidence as $e) {
            $md[] = "- **{$e->public_id}** ({$e->source}/{$e->kind}) {$e->summary}";
        }
        $md[] = '';

        $md[] = '### Actions taken';
        $md[] = '';
        foreach ($this->investigation->actions as $a) {
            $md[] = "- `{$a->phase}` **{$a->tool}** — ".Str::limit($a->result_summary ?? $a->status, 120);
        }

        return implode("\n", $md);
    }

    /** @return \Illuminate\Support\Collection<int,Hypothesis> */
    private function otherHypotheses()
    {
        $lead = $this->leadHypothesis();

        return $this->investigation->hypotheses
            ->where('status', 'accepted')
            ->where('stance', 'cause')
            ->reject(fn (Hypothesis $h) => $lead && $h->id === $lead->id)
            ->take(2);
    }

    public function suspectSha(): ?string
    {
        return $this->leadHypothesis()?->root_cause_sha;
    }

    public function evidenceList(): array
    {
        return $this->investigation->evidence
            ->map(fn (Evidence $e) => "{$e->public_id}: {$e->summary}")
            ->all();
    }
}
