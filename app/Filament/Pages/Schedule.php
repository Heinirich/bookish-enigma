<?php

namespace App\Filament\Pages;

use App\Models\Incident;
use App\Models\Investigation;
use App\Models\ScheduleSetting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * How often the loop runs, and how often it has actually been running.
 *
 * Both halves matter: a cadence you set is an intention, and the observed rate
 * is what the system really did. Seeing them side by side is how you notice the
 * hourly cap silently swallowing investigations.
 */
class Schedule extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Schedule';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.schedule';

    public ?array $data = [];

    public function getTitle(): string
    {
        return 'Schedule';
    }

    public function getSubheading(): ?string
    {
        return 'How often the service is polled, when an incident triggers an investigation, '
            .'and the ceiling on automated runs.';
    }

    public function mount(): void
    {
        $this->form->fill(ScheduleSetting::current()->only([
            'monitoring_enabled', 'ping_interval_seconds', 'detect_interval_minutes',
            'auto_investigate', 'incident_cooldown_minutes', 'max_investigations_per_hour',
            'quiet_hours_start', 'quiet_hours_end',
        ]));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Monitoring')
                ->description('How often /healthz is polled and evaluated.')
                ->icon('heroicon-o-signal')
                ->schema([
                    Toggle::make('monitoring_enabled')
                        ->label('Monitoring active')
                        ->helperText('Off pauses polling and detection entirely. Manual investigations still work.'),

                    Select::make('ping_interval_seconds')
                        ->label('Poll /healthz every')
                        ->options([
                            5 => '5 seconds — 720/hour',
                            10 => '10 seconds — 360/hour (recommended)',
                            15 => '15 seconds — 240/hour',
                            30 => '30 seconds — 120/hour',
                            60 => '60 seconds — 60/hour',
                        ])
                        ->native(false)
                        ->required()
                        ->helperText('Detection needs at least 20 samples in the baseline window, so slower polling means slower detection.'),

                    Select::make('detect_interval_minutes')
                        ->label('Evaluate for incidents every')
                        ->options([
                            1 => 'minute', 2 => '2 minutes', 5 => '5 minutes',
                            10 => '10 minutes', 15 => '15 minutes', 30 => '30 minutes',
                        ])
                        ->native(false)
                        ->required(),
                ]),

            Section::make('Investigations')
                ->description('What happens when an incident is detected.')
                ->icon('heroicon-o-sparkles')
                ->schema([
                    Toggle::make('auto_investigate')
                        ->label('Investigate automatically')
                        ->helperText('Off still opens incidents — you press Investigate yourself.')
                        ->live(),

                    Select::make('max_investigations_per_hour')
                        ->label('At most')
                        ->options([
                            1 => '1 per hour', 2 => '2 per hour', 3 => '3 per hour',
                            6 => '6 per hour (recommended)', 12 => '12 per hour', 30 => '30 per hour',
                        ])
                        ->native(false)
                        ->required()
                        ->visible(fn (callable $get) => $get('auto_investigate'))
                        ->helperText('Each run is roughly 25s of local inference. This cap is what stops a flapping endpoint queueing runs faster than the worker drains them.'),

                    Select::make('incident_cooldown_minutes')
                        ->label('Ignore repeat incidents for')
                        ->options([
                            5 => '5 minutes', 10 => '10 minutes', 15 => '15 minutes (recommended)',
                            30 => '30 minutes', 60 => 'an hour',
                        ])
                        ->native(false)
                        ->required()
                        ->helperText('Stops one ongoing degradation opening an incident every cycle.'),

                    Select::make('quiet_hours_start')
                        ->label('Quiet hours from (UTC)')
                        ->options(self::hourOptions())
                        ->native(false)
                        ->placeholder('no quiet hours')
                        ->visible(fn (callable $get) => $get('auto_investigate')),

                    Select::make('quiet_hours_end')
                        ->label('Quiet hours until (UTC)')
                        ->options(self::hourOptions())
                        ->native(false)
                        ->placeholder('no quiet hours')
                        ->visible(fn (callable $get) => $get('auto_investigate'))
                        ->helperText('Incidents are still opened during quiet hours; only automatic investigation pauses.'),
                ]),
        ]);
    }

    private static function hourOptions(): array
    {
        return collect(range(0, 23))
            ->mapWithKeys(fn (int $h) => [$h => sprintf('%02d:00', $h)])
            ->all();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ScheduleSetting::current()->update($data);

        Notification::make()
            ->title('Schedule updated')
            ->body('Applied on the next scheduler tick — no restart needed.')
            ->success()
            ->send();
    }

    public function settings(): ScheduleSetting
    {
        return ScheduleSetting::current();
    }

    /** What actually happened, as opposed to what was configured. */
    public function observed(): array
    {
        $settings = $this->settings();

        $last24h = Investigation::where('created_at', '>=', now()->subDay())->count();
        $last7d = Investigation::where('created_at', '>=', now()->subWeek())->count();
        $lastHour = Investigation::where('created_at', '>=', now()->subHour())->count();

        $latest = Investigation::latest('created_at')->first();

        $durations = Investigation::whereNotNull('duration_ms')
            ->where('created_at', '>=', now()->subWeek())
            ->pluck('duration_ms');

        return [
            'last_hour' => $lastHour,
            'last_24h' => $last24h,
            'last_7d' => $last7d,
            'cap' => $settings->max_investigations_per_hour,
            'at_cap' => $lastHour >= $settings->max_investigations_per_hour,
            'incidents_24h' => Incident::where('detected_at', '>=', now()->subDay())->count(),
            'latest_at' => $latest?->created_at,
            'mean_duration_s' => $durations->isEmpty() ? null : round($durations->avg() / 1000, 1),
            'mean_gap_minutes' => $this->meanGapMinutes(),
            'expected_per_day' => $settings->auto_investigate
                ? $settings->max_investigations_per_hour * 24
                : 0,
        ];
    }

    /** Average minutes between consecutive investigations over the last week. */
    private function meanGapMinutes(): ?float
    {
        $times = Investigation::where('created_at', '>=', now()->subWeek())
            ->orderBy('created_at')
            ->pluck('created_at');

        if ($times->count() < 2) {
            return null;
        }

        $gaps = $times->sliding(2)->map(
            fn ($pair) => $pair->first()->diffInSeconds($pair->last()),
        );

        return round($gaps->avg() / 60, 1);
    }

    /** Investigations per hour over the last 24h, for a sparkline. */
    public function hourlyHistogram(): array
    {
        $rows = DB::table('investigations')
            ->selectRaw("date_trunc('hour', created_at) as bucket, count(*) as n")
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('n', 'bucket');

        $series = [];

        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i)->startOfHour();
            $series[] = [
                'label' => $hour->format('H:i'),
                'count' => (int) ($rows[$hour->toDateTimeString()] ?? $rows[$hour->format('Y-m-d H:i:sP')] ?? 0),
            ];
        }

        return $series;
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
