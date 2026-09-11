<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Agent\Investigator;
use App\Jobs\RunInvestigation;
use App\Models\Incident;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('detected_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sev1' => 'danger',
                        'sev2' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('title')
                    ->searchable()
                    ->limit(52)
                    ->wrap(),

                TextColumn::make('detection_signal.latency_ratio')
                    ->label('Spike')
                    ->formatStateUsing(fn ($state) => $state ? $state.'×' : '—')
                    ->color('warning'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'danger',
                        'investigating' => 'warning',
                        'resolved' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('latestInvestigation.status')
                    ->label('Agent')
                    ->badge()
                    ->placeholder('not started')
                    ->color(fn (?string $state) => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        null => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')->options([
                    'sev1' => 'Sev 1', 'sev2' => 'Sev 2', 'sev3' => 'Sev 3',
                ]),
                SelectFilter::make('status')->options([
                    'open' => 'Open', 'investigating' => 'Investigating', 'resolved' => 'Resolved',
                ]),
            ])
            ->recordActions([
                Action::make('exportPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (Incident $record) => $record->latestInvestigation?->status === 'completed')
                    ->url(fn (Incident $record) => route('incidents.report.pdf', ['incident' => $record]))
                    ->openUrlInNewTab(),

                Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Run the agent on this incident?')
                    ->modalDescription('Gathers evidence, generates validated hypotheses, then stages '
                        .'the Slack, Jira and Notion write-ups for your approval. Nothing is sent until you approve it.')
                    ->action(function (Incident $record, $livewire) {
                        // Re-opening the live view beats starting a second run:
                        // two investigations of one incident produce two sets of
                        // write actions, and only the newer is visible anywhere.
                        if ($running = $record->runningInvestigation()) {
                            $livewire->dispatch('investigation-watch', investigationId: $running->id);

                            return;
                        }

                        // Created synchronously rather than inside the job: the
                        // live panel needs an id to poll from the moment it opens.
                        $investigation = Investigator::open($record);
                        $record->update(['status' => 'investigating']);

                        RunInvestigation::dispatch($record, $investigation->id);

                        $livewire->dispatch('investigation-started', investigationId: $investigation->id);
                    }),
            ]);
    }
}
