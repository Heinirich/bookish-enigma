<?php

namespace App\Livewire;

use App\Models\AgentAction;
use App\Models\Investigation;
use Illuminate\Support\Facades\Redis;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live view of an investigation while it runs.
 *
 * An investigation takes ~25s on the local model, which is long enough that a
 * spinner is uninformative and a page reload loses the thread. This streams the
 * action log as it is written, so the reasoning is visible while it happens
 * rather than only in retrospect.
 *
 * Rendered once at the panel layout's body end, so any page can open it by
 * dispatching `investigation-started`.
 */
class InvestigationStream extends Component
{
    public ?int $investigationId = null;

    public bool $open = false;

    /** Phases in the order the Investigator moves through them. */
    private const PHASES = [
        'queued' => 'Queued',
        'gathering' => 'Gathering',
        'exploring' => 'Exploring',
        'synthesizing' => 'Synthesizing',
        'acting' => 'Staging writes',
        'completed' => 'Done',
    ];

    #[On('investigation-started')]
    public function start(int $investigationId): void
    {
        $this->investigationId = $investigationId;
        $this->open = true;
    }

    #[On('investigation-watch')]
    public function watch(int $investigationId): void
    {
        $this->start($investigationId);
    }

    public function close(): void
    {
        $this->open = false;
        $this->investigationId = null;
    }

    public function getInvestigationProperty(): ?Investigation
    {
        if (! $this->investigationId) {
            return null;
        }

        return Investigation::with(['incident', 'hypotheses.evidence', 'actions'])
            ->find($this->investigationId);
    }

    public function getIsRunningProperty(): bool
    {
        $status = $this->investigation?->status;

        return $status !== null && ! in_array($status, ['completed', 'failed'], true);
    }

    /**
     * A queued job that never moves means no worker is consuming the queue --
     * the single most likely reason this panel would sit still forever, so it is
     * worth naming explicitly rather than spinning.
     */
    public function getIsStalledProperty(): bool
    {
        $investigation = $this->investigation;

        if ($investigation?->status !== 'queued') {
            return false;
        }

        return $investigation->created_at->diffInSeconds(now()) > 8;
    }

    public function getPendingJobsProperty(): ?int
    {
        try {
            return (int) Redis::llen('queues:default');
        } catch (\Throwable) {
            return null;
        }
    }

    public function getPhasesProperty(): array
    {
        $current = $this->investigation?->status ?? 'queued';
        $keys = array_keys(self::PHASES);

        // 'failed' has no position in the sequence; freeze the stepper where it stopped.
        $currentIndex = $current === 'failed'
            ? count($keys) - 1
            : (array_search($current, $keys, true) ?: 0);

        return collect(self::PHASES)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'state' => match (true) {
                    $current === 'failed' && $key === 'completed' => 'failed',
                    array_search($key, $keys, true) < $currentIndex => 'done',
                    $key === $current => 'active',
                    default => 'pending',
                },
            ])
            ->values()
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int,AgentAction> */
    public function getActionsProperty()
    {
        return $this->investigation?->actions->sortBy('sequence')->values() ?? collect();
    }

    public function getVerificationProperty(): array
    {
        $hypotheses = $this->investigation?->hypotheses ?? collect();

        return [
            'generated' => $hypotheses->count(),
            'accepted' => $hypotheses->where('status', 'accepted')->count(),
            'discarded' => $hypotheses->where('status', '!=', 'accepted')->count(),
            'citations' => $hypotheses->sum(fn ($h) => count($h->validation_report['cited'] ?? [])),
            'fabricated' => $hypotheses->sum(fn ($h) => count($h->validation_report['fabricated'] ?? [])),
            'evidence' => $this->investigation?->evidence()->count() ?? 0,
        ];
    }

    public function render()
    {
        return view('filament.livewire.investigation-stream');
    }
}
