<?php

namespace App\Filament\Resources\EvaluationRuns\Pages;

use App\Filament\Resources\EvaluationRuns\EvaluationRunResource;
use App\Models\EvaluationRun;
use Filament\Resources\Pages\Page;

class ViewEvaluationRun extends Page
{
    protected static string $resource = EvaluationRunResource::class;

    protected string $view = 'filament.pages.view-evaluation-run';

    public EvaluationRun $record;

    public function mount(int|string $record): void
    {
        $this->record = EvaluationRun::with('scores.investigation.incident')->findOrFail($record);
    }

    public function getTitle(): string
    {
        return $this->record->label;
    }

    public function getSubheading(): ?string
    {
        return $this->record->model.' · '.$this->record->scenario_count.' scenarios · '
            .$this->record->started_at->diffForHumans();
    }
}
