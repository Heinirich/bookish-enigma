<?php

namespace App\Console\Commands;

use App\Agent\Investigator;
use App\Models\Hypothesis;
use App\Models\Incident;
use Illuminate\Console\Command;

class InvestigateCommand extends Command
{
    protected $signature = 'investigate
                            {incident? : Incident reference or id; defaults to the newest open incident}';

    protected $description = 'Run an investigation synchronously and print the findings';

    public function handle(Investigator $investigator): int
    {
        $incident = $this->resolveIncident();

        if (! $incident) {
            $this->error('No incident found. Try: php artisan demo:seed slow-query --fresh && php artisan health:detect');

            return self::FAILURE;
        }

        $this->info("Investigating {$incident->reference}: {$incident->title}");
        $this->newLine();

        $investigation = Investigator::open($incident);
        $incident->update(['status' => 'investigating']);

        $investigation = $investigator->run($investigation);

        if ($investigation->status === 'failed') {
            $this->error("Investigation failed: {$investigation->failure_reason}");

            return self::FAILURE;
        }

        $this->renderActions($investigation);
        $this->renderHypotheses($investigation);
        $this->renderVerification($investigation);

        return self::SUCCESS;
    }

    private function resolveIncident(): ?Incident
    {
        $ref = $this->argument('incident');

        if (! $ref) {
            return Incident::whereIn('status', ['open', 'investigating'])->latest('detected_at')->first();
        }

        // Postgres will not compare a bigint column to a non-numeric string, so
        // the id branch is only added when the argument actually looks like one.
        return Incident::where('reference', $ref)
            ->when(is_numeric($ref), fn ($query) => $query->orWhere('id', (int) $ref))
            ->first();
    }

    private function renderActions($investigation): void
    {
        $this->line('<options=bold>Action log</>');

        foreach ($investigation->actions as $action) {
            $marker = match ($action->status) {
                'succeeded' => '<fg=green>✓</>',
                'failed' => '<fg=red>✗</>',
                default => '<fg=yellow>•</>',
            };

            $this->line(sprintf(
                '  %s %-2d %-24s %-11s %s',
                $marker,
                $action->sequence,
                $action->tool,
                $action->phase,
                \Illuminate\Support\Str::limit($action->result_summary ?? '', 70),
            ));
        }

        $this->newLine();
    }

    private function renderHypotheses($investigation): void
    {
        $this->line('<options=bold>Hypotheses</>');

        if ($investigation->hypotheses->isEmpty()) {
            $this->warn('  none produced');

            return;
        }

        foreach ($investigation->hypotheses as $h) {
            $badge = $h->status === 'accepted'
                ? '<fg=green>ACCEPTED</>'
                : '<fg=red>DISCARDED — unsupported</>';

            $this->newLine();
            $this->line("  #{$h->rank} {$badge}  confidence ".number_format($h->confidence, 2));
            $this->line('     '.wordwrap($h->statement, 92, "\n     "));

            $cited = $h->evidence->map(fn ($e) => $e->public_id)->implode(', ');
            $this->line('     evidence: '.($cited ?: '—')
                .'   grounding: '.number_format((float) $h->grounding_score, 2));

            $fabricated = $h->validation_report['fabricated'] ?? [];
            if ($fabricated !== []) {
                $this->line('     <fg=red>fabricated citations: '.implode(', ', $fabricated).'</>');
            }

            $ungrounded = $h->validation_report['ungrounded_tokens'] ?? [];
            if ($ungrounded !== []) {
                $this->line('     <fg=yellow>figures not found in cited evidence: '.implode(', ', $ungrounded).'</>');
            }

            if ($h->root_cause_sha) {
                $this->line('     blames deploy: '.substr($h->root_cause_sha, 0, 7));
            }
        }

        $this->newLine();
    }

    private function renderVerification($investigation): void
    {
        $all = $investigation->hypotheses;
        $accepted = $all->where('status', 'accepted');

        $totalCited = $all->sum(fn (Hypothesis $h) => count($h->validation_report['cited'] ?? []));
        $totalFabricated = $all->sum(fn (Hypothesis $h) => count($h->validation_report['fabricated'] ?? []));

        $this->line('<options=bold>Verification</>');
        $this->line("  claims generated   {$all->count()}");
        $this->line("  accepted           {$accepted->count()}");
        $this->line('  discarded          '.($all->count() - $accepted->count()));
        $this->line("  citations checked  {$totalCited}");
        $this->line('  fabricated         '.($totalFabricated > 0
            ? "<fg=red>{$totalFabricated}</>"
            : "<fg=green>0</>"));
        $this->line('  evidence on file   '.$investigation->evidence->count());
        $this->line('  tokens             '.$investigation->prompt_tokens.' in / '
            .$investigation->completion_tokens.' out');
        $this->line('  duration           '.number_format($investigation->duration_ms / 1000, 1).'s');
        $this->newLine();
    }
}
