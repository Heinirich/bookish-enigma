<?php

namespace App\Providers;

use App\Agent\JanClient;
use App\Agent\ToolRegistry;
use App\Agent\Tools\FetchCommitDiffTool;
use App\Agent\Tools\ListDeploymentsTool;
use App\Agent\Tools\QueryMetricsTool;
use App\Agent\Tools\SearchPastIncidentsTool;
use App\Livewire\InvestigationStream;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JanClient::class, fn () => JanClient::fromConfig());

        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(QueryMetricsTool::class),
            $app->make(ListDeploymentsTool::class),
            $app->make(FetchCommitDiffTool::class),
            $app->make(SearchPastIncidentsTool::class),
        ]));
    }

    public function boot(): void
    {
        Livewire::component('investigation-stream', InvestigationStream::class);
    }
}
