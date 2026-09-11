<?php

namespace App\Filament\Resources\EvaluationRuns;

use App\Filament\Resources\EvaluationRuns\Pages\ListEvaluationRuns;
use App\Filament\Resources\EvaluationRuns\Pages\ViewEvaluationRun;
use App\Models\EvaluationRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EvaluationRunResource extends Resource
{
    protected static ?string $model = EvaluationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Evaluation';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        // Runs come from `php artisan eval:run`, never from a form.
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('label')->searchable()->weight('bold')->limit(34),

                TextColumn::make('model')
                    ->badge()
                    ->color('gray')
                    ->limit(24),

                TextColumn::make('scenario_count')->label('Scenarios')->alignCenter(),

                TextColumn::make('root_cause_hit_rate')
                    ->label('Root cause')
                    ->formatStateUsing(fn (?float $s) => self::pct($s))
                    ->color(fn (?float $s) => self::gradeColor($s))
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('evidence_real_rate')
                    ->label('Evidence real')
                    ->formatStateUsing(fn (?float $s) => self::pct($s))
                    // Anything below 100% means a fabricated citation slipped through.
                    ->color(fn (?float $s) => $s !== null && $s < 1.0 ? 'danger' : 'success')
                    ->alignCenter(),

                TextColumn::make('grounding_rate')
                    ->label('Grounding')
                    ->formatStateUsing(fn (?float $s) => self::pct($s))
                    ->color(fn (?float $s) => self::gradeColor($s))
                    ->alignCenter(),

                TextColumn::make('brier_score')
                    ->label('Brier')
                    ->formatStateUsing(fn (?float $s) => $s === null ? '—' : number_format($s, 3))
                    // Lower is better here, unlike every other column.
                    ->color(fn (?float $s) => match (true) {
                        $s === null => 'gray',
                        $s <= 0.1 => 'success',
                        $s <= 0.25 => 'warning',
                        default => 'danger',
                    })
                    ->alignCenter(),

                TextColumn::make('started_at')->label('Run')->since()->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('view')
                    ->label('Detail')
                    ->url(fn (EvaluationRun $record) => ViewEvaluationRun::getUrl(['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvaluationRuns::route('/'),
            'view' => ViewEvaluationRun::route('/{record}'),
        ];
    }

    public static function pct(?float $score): string
    {
        return $score === null ? '—' : number_format($score * 100, 1).'%';
    }

    public static function gradeColor(?float $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 0.9 => 'success',
            $score >= 0.6 => 'warning',
            default => 'danger',
        };
    }
}
