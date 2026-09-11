<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EvalComparison;
use App\Filament\Widgets\LatencyChart;
use App\Filament\Widgets\SystemOverview;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 0;

    public function getTitle(): string
    {
        return 'Overview';
    }

    public function getSubheading(): ?string
    {
        return 'Self-monitored payments API, and how the agent is performing against known answers.';
    }

    public function getWidgets(): array
    {
        return [
            SystemOverview::class,
            LatencyChart::class,
            EvalComparison::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
