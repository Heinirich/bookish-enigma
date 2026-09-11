<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Agent\ActionExecutor;
use App\Agent\Investigator;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Jobs\RunInvestigation;
use App\Models\AgentAction;
use App\Models\Hypothesis;
use App\Models\Investigation;
use App\Reporting\InvestigationReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * The audit surface: every claim, the evidence it rests on, and what the agent
 * actually did -- side by side, so a reader can check the conclusion rather than
 * take it on trust.
 */
class ViewIncident extends Page
{
    protected static string $resource = IncidentResource::class;

    protected string $view = 'filament.pages.view-incident';

    public $record;

    /** Which run is being shown; null means the most recent. */
    public ?int $selectedInvestigationId = null;

    public function mount(int|string $record): void
    {
        $this->record = IncidentResource::getModel()::findOrFail($record);
    }

    /**
     * An incident accumulates runs -- a retry after a fix, a re-run on a better
     * model -- and previously only the newest was reachable, leaving the earlier
     * ones invisible along with any write actions still awaiting approval.
     */
    public function selectInvestigation(int $investigationId): void
    {
        $this->selectedInvestigationId = $investigationId;
    }

    /** @return \Illuminate\Support\Collection<int,Investigation> */
    public function investigationHistory()
    {
        return $this->record->investigations()
            ->withCount(['hypotheses', 'evidence'])
            ->orderByDesc('id')
            ->get();
    }

    public function getTitle(): string
    {
        return "{$this->record->reference} — {$this->record->title}";
    }

    public function getInvestigation(): ?Investigation
    {
        $query = $this->record->investigations()
            ->with(['hypotheses.evidence', 'evidence', 'actions']);

        return $this->selectedInvestigationId
            ? (clone $query)->whereKey($this->selectedInvestigationId)->first()
                ?? $query->latest('id')->first()
            : $query->latest('id')->first();
    }

    public function getReport(): ?InvestigationReport
    {
        $investigation = $this->getInvestigation();

        return $investigation ? InvestigationReport::for($investigation) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('investigate')
                ->label(fn () => $this->getInvestigation() ? 'Re-investigate' : 'Investigate')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription('Write actions are staged for approval, not sent.')
                // $livewire is injected by Filament; using it rather than $this
                // keeps the dispatch working regardless of how the action closure
                // is bound.
                ->action(function ($livewire) {
                    if ($running = $this->record->runningInvestigation()) {
                        $livewire->dispatch('investigation-watch', investigationId: $running->id);

                        Notification::make()
                            ->title('Already running')
                            ->body('Showing the investigation in progress rather than starting a second one.')
                            ->info()
                            ->send();

                        return;
                    }

                    $investigation = Investigator::open($this->record);
                    $this->record->update(['status' => 'investigating']);

                    RunInvestigation::dispatch($this->record, $investigation->id);

                    $livewire->dispatch('investigation-started', investigationId: $investigation->id);
                }),

            Action::make('watch')
                ->label('Live view')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => $this->getInvestigation() !== null)
                ->action(fn ($livewire) => $livewire->dispatch(
                    'investigation-watch',
                    investigationId: $this->getInvestigation()->id,
                )),

            Action::make('resolve')
                ->label(fn () => $this->record->isResolved() ? 'Reopen' : 'Resolve')
                ->icon(fn () => $this->record->isResolved() ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->isResolved() ? 'gray' : 'success')
                ->schema(fn () => $this->record->isResolved() ? [] : [
                    Textarea::make('resolution_note')
                        ->label('What actually fixed it?')
                        ->rows(3)
                        ->placeholder('Reverted the deploy; latency returned to baseline within two minutes.')
                        ->helperText('Recorded alongside the agent\'s conclusions so the two can be compared later.'),

                    TextInput::make('actual_cause_sha')
                        ->label('Actual cause (commit SHA)')
                        ->placeholder('leave blank if no deploy was responsible')
                        ->helperText('If this differs from what the agent blamed, that difference is the finding.'),
                ])
                ->action(function (array $data) {
                    if ($this->record->isResolved()) {
                        $this->record->update([
                            'resolved_at' => null, 'resolution_note' => null,
                            'resolved_by' => null, 'actual_cause_sha' => null,
                            'status' => 'open',
                        ]);

                        Notification::make()->title('Incident reopened')->warning()->send();

                        return;
                    }

                    $this->record->update([
                        'resolved_at' => now(),
                        'resolution_note' => $data['resolution_note'] ?? null,
                        'actual_cause_sha' => $data['actual_cause_sha'] ?: null,
                        'resolved_by' => auth()->user()?->email ?? 'operator',
                        'status' => 'resolved',
                    ]);

                    Notification::make()->title('Incident resolved')->success()->send();
                }),

            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->getInvestigation()?->status === 'completed')
                ->url(fn () => route('incidents.report.pdf', ['incident' => $this->record]))
                ->openUrlInNewTab(),

            Action::make('approveAll')
                ->label('Approve all writes')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn () => $this->pendingActions()->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading('Send to Slack, Jira and Notion?')
                ->modalDescription(fn () => 'This creates real records in every connector currently set to `api`. '
                    .$this->pendingActions()->count().' action(s) will run.')
                ->action(function (ActionExecutor $executor) {
                    $this->pendingActions()->each(fn (AgentAction $a) => $executor->execute($a, 'filament'));

                    Notification::make()->title('Write actions executed')->success()->send();
                }),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,AgentAction> */
    public function pendingActions()
    {
        $investigation = $this->getInvestigation();

        if (! $investigation) {
            return collect();
        }

        return $investigation->actions->where('status', 'pending_approval')->values();
    }

    public function recordVerdict(int $hypothesisId, string $verdict): void
    {
        abort_unless(in_array($verdict, ['confirmed', 'refuted'], true), 400);

        $hypothesis = Hypothesis::findOrFail($hypothesisId);

        // Clicking the same verdict again clears it, so a mis-click is undoable
        // without a separate control.
        $isToggleOff = $hypothesis->verdict === $verdict;

        $hypothesis->update([
            'verdict' => $isToggleOff ? null : $verdict,
            'verdict_by' => $isToggleOff ? null : (auth()->user()?->email ?? 'operator'),
            'verdict_at' => $isToggleOff ? null : now(),
        ]);

        Notification::make()
            ->title($isToggleOff ? 'Verdict cleared' : "Marked {$verdict}")
            ->body($isToggleOff ? null : 'This feeds the measured accuracy on the dashboard.')
            ->success()
            ->send();
    }

    public function approveAction(int $actionId): void
    {
        $action = AgentAction::findOrFail($actionId);
        app(ActionExecutor::class)->execute($action, 'filament');

        Notification::make()->title('Executed '.$action->tool)->success()->send();
    }

    public function rejectAction(int $actionId): void
    {
        $action = AgentAction::findOrFail($actionId);
        app(ActionExecutor::class)->reject($action, 'filament');

        Notification::make()->title('Rejected '.$action->tool)->warning()->send();
    }
}
