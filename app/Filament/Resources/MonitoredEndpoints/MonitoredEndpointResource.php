<?php

namespace App\Filament\Resources\MonitoredEndpoints;

use App\Filament\Resources\MonitoredEndpoints\Pages\ListMonitoredEndpoints;
use App\Models\MonitoredEndpoint;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonitoredEndpointResource extends Resource
{
    protected static ?string $model = MonitoredEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Services';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(80)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),

            TextInput::make('slug')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true)
                ->helperText('Used in logs and charts.'),

            TextInput::make('url')
                ->label('Health check URL')
                ->required()
                ->url()
                ->maxLength(2048)
                ->placeholder('https://api.example.com/healthz')
                // The pinger fetches whatever is entered here, so the scheme is
                // constrained rather than trusted.
                ->rule('regex:/^https?:\/\//i')
                ->helperText('Must be http or https. Polled on the cadence set under Schedule.'),

            TextInput::make('service')
                ->required()
                ->maxLength(80)
                ->helperText('The name the agent uses when describing this service.'),

            Select::make('expected_status')
                ->label('Healthy response')
                ->options([200 => '200 OK', 201 => '201 Created', 204 => '204 No Content'])
                ->default(200)
                ->native(false)
                ->required()
                ->helperText('Anything else counts as an error for detection.'),

            Toggle::make('is_active')
                ->label('Actively polled')
                ->default(true)
                ->helperText('Off keeps the history but stops new samples.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('url')->limit(44)->color('gray')->copyable(),
                TextColumn::make('service')->badge()->color('gray'),

                IconColumn::make('is_active')
                    ->label('Polling')
                    ->boolean(),

                TextColumn::make('samples')
                    ->label('Last 15 min')
                    ->state(fn (MonitoredEndpoint $record) => self::recentSummary($record))
                    ->color(fn (MonitoredEndpoint $record) => str_contains(self::recentSummary($record), 'no data')
                        ? 'gray'
                        : 'success'),

                TextColumn::make('incidents_count')->counts('incidents')->label('Incidents'),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    // Read-only: one GET, nothing recorded, so a misconfigured URL
                    // can be corrected before it pollutes the latency history.
                    ->action(function (MonitoredEndpoint $record) {
                        $startedAt = microtime(true);

                        try {
                            $response = Http::timeout(10)->get($record->url);
                            $ms = (int) round((microtime(true) - $startedAt) * 1000);
                            $ok = $response->status() === $record->expected_status;

                            Notification::make()
                                ->title($ok ? "Reachable — {$response->status()} in {$ms}ms" : "Unexpected status {$response->status()}")
                                ->body($ok ? null : "Expected {$record->expected_status}.")
                                ->status($ok ? 'success' : 'warning')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not reach it')
                                ->body(Str::limit($e->getMessage(), 140))
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a service'),
            ])
            ->emptyStateHeading('No services yet')
            ->emptyStateDescription('Add the health endpoint of a service you want watched.');
    }

    private static function recentSummary(MonitoredEndpoint $record): string
    {
        $row = DB::table('health_checks')
            ->selectRaw('count(*) as n, round(percentile_cont(0.95) within group (order by latency_ms)) as p95')
            ->where('monitored_endpoint_id', $record->id)
            ->where('checked_at', '>=', now()->subMinutes(15))
            ->first();

        return $row && $row->n > 0
            ? "{$row->n} samples · p95 {$row->p95}ms"
            : 'no data';
    }

    public static function getPages(): array
    {
        return ['index' => ListMonitoredEndpoints::route('/')];
    }
}
