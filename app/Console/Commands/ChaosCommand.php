<?php

namespace App\Console\Commands;

use App\Chaos\ChaosProfile;
use App\Chaos\ScenarioActivator;
use App\Chaos\ScenarioLibrary;
use Illuminate\Console\Command;

class ChaosCommand extends Command
{
    protected $signature = 'chaos
                            {action : activate|reset|status|list}
                            {scenario? : Scenario key when activating}';

    protected $description = 'Activate or clear a seeded degradation scenario on /healthz';

    public function handle(ScenarioActivator $activator): int
    {
        return match ($this->argument('action')) {
            'activate' => $this->activate($activator),
            'reset' => $this->reset($activator),
            'status' => $this->status(),
            'list' => $this->list(),
            default => $this->fail('Unknown action. Use activate, reset, status or list.'),
        };
    }

    private function activate(ScenarioActivator $activator): int
    {
        $key = $this->argument('scenario');

        if (! $key || ! ScenarioLibrary::exists($key)) {
            $this->error('Pick a scenario: '.implode(', ', ScenarioLibrary::keys()));

            return self::FAILURE;
        }

        $deployment = $activator->activate($key);
        $scenario = ScenarioLibrary::get($key);

        $this->info("Activated [{$key}] — {$scenario['label']}");
        $this->line("  culprit deploy  {$deployment->shortSha()}  {$deployment->deployed_at->toTimeString()}");
        $this->line('  decoys deployed '.count($scenario['decoys']));
        $this->line("  profile         {$scenario['profile']['base_latency_ms']}ms base"
            .", {$scenario['profile']['jitter_ms']}ms jitter"
            .', '.($scenario['profile']['error_rate'] * 100).'% errors');

        return self::SUCCESS;
    }

    private function reset(ScenarioActivator $activator): int
    {
        $activator->deactivate();
        $this->info('Chaos cleared. /healthz is healthy again.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $p = ChaosProfile::current();

        $this->table(['field', 'value'], [
            ['scenario', $p->scenarioKey ?? '— healthy —'],
            ['label', $p->label],
            ['base latency', "{$p->baseLatencyMs}ms"],
            ['jitter', "{$p->jitterMs}ms"],
            ['error rate', ($p->errorRate * 100).'%'],
            ['deploy sha', $p->deploySha ? substr($p->deploySha, 0, 7) : '—'],
            ['activated at', $p->activatedAt ?? '—'],
        ]);

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rows = [];

        foreach (ScenarioLibrary::all() as $key => $s) {
            $rows[] = [
                $key,
                $s['label'],
                $s['severity'],
                "{$s['profile']['base_latency_ms']}ms",
                ($s['profile']['error_rate'] * 100).'%',
                count($s['decoys']),
            ];
        }

        $this->table(['key', 'label', 'sev', 'latency', 'errors', 'decoys'], $rows);

        return self::SUCCESS;
    }
}
