<?php

namespace App\Console\Commands;

use App\Connectors\ConnectorSettingsRepository;
use App\Connectors\Contracts\GitHubConnector;
use App\Models\Deployment;
use Illuminate\Console\Command;

class GithubSyncCommand extends Command
{
    protected $signature = 'github:sync {--minutes=120 : How far back to pull}';

    protected $description = 'Pull deployments and commits from GitHub into local storage';

    public function handle(GitHubConnector $github): int
    {
        // A fake connector reports itself configured -- that is what makes the
        // offline loop work -- so the driver is what decides whether there is a
        // real repository to pull from.
        $driver = app(ConnectorSettingsRepository::class)->resolve('github')['driver'] ?? 'fake';

        if ($driver !== 'api' || ! $github->isConfigured()) {
            $this->warn('GitHub is on the fake driver, so there is no repository to pull from.');
            $this->line('  Deployments will come from seeded scenarios instead.');
            $this->line('  Switch it to the API driver at /admin/connectors.');

            return self::SUCCESS;
        }

        $minutes = (int) $this->option('minutes');
        $from = now()->subMinutes($minutes);

        $this->info("Pulling deployments since {$from->toDateTimeString()} UTC…");

        $synced = $github->sync($from, now());

        $this->info("Synced {$synced} deployment(s).");

        if ($synced > 0) {
            $this->table(
                ['sha', 'deployed at', 'message'],
                Deployment::with('commit')
                    ->where('deployed_at', '>=', $from)
                    ->latest('deployed_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (Deployment $d) => [
                        $d->shortSha(),
                        $d->deployed_at->toDateTimeString(),
                        \Illuminate\Support\Str::limit($d->commit?->message ?? '—', 54),
                    ])
                    ->all(),
            );
        }

        return self::SUCCESS;
    }
}
