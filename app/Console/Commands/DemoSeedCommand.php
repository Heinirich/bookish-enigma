<?php

namespace App\Console\Commands;

use App\Chaos\ScenarioActivator;
use App\Chaos\ScenarioLibrary;
use App\Models\Commit;
use App\Models\Deployment;
use App\Models\HealthCheck;
use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Monitoring\SyntheticHistory;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
                            {scenario : Scenario key, or "random"}
                            {--fresh : Clear existing incidents, deploys and samples first}
                            {--minutes=35 : Minutes of synthetic history to write}';

    protected $description = 'Stage a scenario end to end: deploys, chaos profile, and sample history';

    public function handle(ScenarioActivator $activator, SyntheticHistory $history): int
    {
        $key = $this->argument('scenario');

        if ($key === 'random') {
            $key = ScenarioLibrary::keys()[array_rand(ScenarioLibrary::keys())];
        }

        if (! ScenarioLibrary::exists($key)) {
            $this->error('Unknown scenario. Available: '.implode(', ', ScenarioLibrary::keys()));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->clear();
            $this->line('Cleared prior demo data.');
        }

        $endpoint = MonitoredEndpoint::where('is_active', true)->firstOrFail();

        $culprit = $activator->activate($key);
        $count = $history->generate($endpoint, $key, now(), (int) $this->option('minutes'));

        $scenario = ScenarioLibrary::get($key);

        $this->newLine();
        $this->info("Staged [{$key}] — {$scenario['label']}");
        $this->line("  culprit deploy   {$culprit->shortSha()}  \"{$culprit->commit?->message}\"");
        $this->line('  decoy deploys    '.Deployment::where('scenario_key', $key)->where('is_seeded_cause', false)->count());
        $this->line("  samples written  {$count}");
        $this->line('  chaos profile    live on /healthz');
        $this->newLine();
        $this->comment('Next: php artisan health:detect');

        return self::SUCCESS;
    }

    private function clear(): void
    {
        // Incidents cascade to investigations, evidence, hypotheses and actions.
        Incident::query()->delete();
        HealthCheck::query()->delete();
        Deployment::query()->delete();
        Commit::query()->delete();
    }
}
