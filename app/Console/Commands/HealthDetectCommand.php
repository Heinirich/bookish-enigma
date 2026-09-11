<?php

namespace App\Console\Commands;

use App\Jobs\RunInvestigation;
use App\Agent\Investigator;
use App\Models\MonitoredEndpoint;
use App\Models\ScheduleSetting;
use App\Monitoring\IncidentDetector;
use Illuminate\Console\Command;

class HealthDetectCommand extends Command
{
    protected $signature = 'health:detect
                            {--no-dispatch : Open incidents without starting an investigation}
                            {--scheduled : Respect the schedule settings and skip if not yet due}';

    protected $description = 'Evaluate recent samples and open incidents on degradation';

    public function handle(IncidentDetector $detector): int
    {
        $settings = ScheduleSetting::current();

        if ($this->option('scheduled')) {
            if (! $settings->shouldDetectNow()) {
                return self::SUCCESS;
            }

            $settings->update(['last_detect_at' => now()]);
        }

        $endpoints = MonitoredEndpoint::where('is_active', true)->get();
        $opened = 0;

        foreach ($endpoints as $endpoint) {
            $signal = $detector->measure($endpoint, now());

            if ($signal === null) {
                $this->line("  {$endpoint->slug}: not enough history yet");

                continue;
            }

            $this->line(sprintf(
                '  %s: baseline p95 %dms (%d samples) → recent p95 %dms (%d samples), ratio %s, errors %d%%',
                $endpoint->slug,
                $signal['baseline']['p95'],
                $signal['baseline']['samples'],
                $signal['spike']['p95'],
                $signal['spike']['samples'],
                $signal['latency_ratio'] ?? '—',
                round($signal['spike']['error_rate'] * 100),
            ));

            if ($incident = $detector->detect($endpoint, now())) {
                $opened++;
                $this->warn("  ↳ opened {$incident->reference}: {$incident->title} [{$incident->severity}]");

                if ($this->option('no-dispatch')) {
                    continue;
                }

                if ($incident->hasRunningInvestigation()) {
                    $this->line('  ↳ investigation already running');

                    continue;
                }

                [$allowed, $reason] = $settings->mayInvestigate();

                if (! $allowed) {
                    $this->line("  ↳ <fg=yellow>investigation not started: {$reason}</>");

                    continue;
                }

                $investigation = Investigator::open($incident);
                $incident->update(['status' => 'investigating']);

                RunInvestigation::dispatch($incident, $investigation->id);
                $this->line('  ↳ investigation queued');
            }
        }

        $this->newLine();
        $this->info($opened === 0 ? 'No new incidents.' : "Opened {$opened} incident(s).");

        return self::SUCCESS;
    }
}
