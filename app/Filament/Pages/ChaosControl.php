<?php

namespace App\Filament\Pages;

use App\Chaos\ChaosProfile;
use App\Chaos\ScenarioActivator;
use App\Chaos\ScenarioLibrary;
use App\Models\MonitoredEndpoint;
use App\Monitoring\IncidentDetector;
use App\Monitoring\SyntheticHistory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Drives the demo, and doubles as the eval fixture generator.
 *
 * Staging a scenario writes the culprit deploy, its decoys, and the degradation
 * in a single act -- so ground truth exists for free and every demo run is a
 * scored case rather than an anecdote.
 */
class ChaosControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBoltSlash;

    protected static ?string $navigationLabel = 'Chaos control';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.chaos-control';

    public function getTitle(): string
    {
        return 'Chaos control';
    }

    public function getSubheading(): ?string
    {
        return 'Stage a degradation on /healthz. The deploy that causes it is recorded, '
            .'so the agent can be scored against a known answer.';
    }

    public function currentProfile(): ChaosProfile
    {
        return ChaosProfile::current();
    }

    public function scenarios(): array
    {
        return ScenarioLibrary::all();
    }

    public function stage(string $key): void
    {
        $endpoint = MonitoredEndpoint::where('is_active', true)->first();

        if (! $endpoint) {
            Notification::make()->title('No monitored endpoint')->danger()->send();

            return;
        }

        app(ScenarioActivator::class)->activate($key);
        app(SyntheticHistory::class)->generate($endpoint, $key, now());

        $incident = app(IncidentDetector::class)->detect($endpoint, now());

        Notification::make()
            ->title($incident ? "Staged — {$incident->reference} opened" : 'Staged')
            ->body($incident
                ? 'The detector opened an incident. Investigate it from the Incidents list.'
                : 'Chaos is live on /healthz, but no incident tripped yet.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Clear chaos')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    app(ScenarioActivator::class)->deactivate();

                    Notification::make()->title('/healthz is healthy again')->success()->send();
                }),
        ];
    }
}
