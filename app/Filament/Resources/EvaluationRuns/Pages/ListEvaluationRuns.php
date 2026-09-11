<?php

namespace App\Filament\Resources\EvaluationRuns\Pages;

use App\Filament\Resources\EvaluationRuns\EvaluationRunResource;
use App\Filament\Widgets\EvalComparison;
use Filament\Resources\Pages\ListRecords;

class ListEvaluationRuns extends ListRecords
{
    protected static string $resource = EvaluationRunResource::class;

    protected function getHeaderWidgets(): array
    {
        return [EvalComparison::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
