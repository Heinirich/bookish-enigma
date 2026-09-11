<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Agent\Investigator;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Widgets\LatencyChart;
use App\Jobs\RunInvestigation;
use App\Models\MonitoredEndpoint;
use App\Monitoring\ManualIncidentOpener;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
            | The vague-alert entry point. Waiting for a threshold to trip is the
            | wrong shape for "someone says the payments API feels slow" -- which
            | is how most investigations actually begin.
            */
            Action::make('investigateFromPrompt')
                ->label('New investigation')
                ->icon('heroicon-o-sparkles')
                ->modalHeading('Investigate from a prompt')
                ->modalDescription('Describe the problem in plain language. The agent gathers the '
                    .'evidence itself and reports back with citations you can check.')
                ->modalSubmitActionLabel('Investigate')
                ->schema([
                    Textarea::make('prompt')
                        ->label('What should the agent look into?')
                        ->placeholder('investigate the payment API latency spike')
                        ->required()
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Vague is fine — the numbers come from the monitoring data, not from this text.'),

                    Select::make('monitored_endpoint_id')
                        ->label('Service')
                        ->options(fn () => MonitoredEndpoint::where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => MonitoredEndpoint::where('is_active', true)->value('id'))
                        ->native(false)
                        ->required(),

                    Select::make('window_minutes')
                        ->label('Look back over')
                        ->options([
                            15 => 'the last 15 minutes',
                            30 => 'the last 30 minutes',
                            60 => 'the last hour',
                            120 => 'the last 2 hours',
                        ])
                        ->default(30)
                        ->native(false)
                        ->required(),
                ])
                ->action(function (array $data, $livewire, ManualIncidentOpener $opener) {
                    $incident = $opener->open(
                        prompt: $data['prompt'],
                        endpoint: MonitoredEndpoint::find($data['monitored_endpoint_id']),
                        windowMinutes: (int) $data['window_minutes'],
                    );

                    $investigation = Investigator::open($incident);
                    $incident->update(['status' => 'investigating']);

                    RunInvestigation::dispatch($incident, $investigation->id);

                    $livewire->dispatch('investigation-started', investigationId: $investigation->id);
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [LatencyChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
