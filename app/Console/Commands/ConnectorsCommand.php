<?php

namespace App\Console\Commands;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorSettingsRepository;
use App\Connectors\ConnectorTester;
use Illuminate\Console\Command;

class ConnectorsCommand extends Command
{
    protected $signature = 'connectors
                            {action=status : status|test}
                            {key? : A single connector key, or all}';

    protected $description = 'Show connector configuration and test credentials against the live APIs';

    public function handle(ConnectorSettingsRepository $settings, ConnectorTester $tester): int
    {
        $keys = $this->resolveKeys();

        if ($keys === []) {
            $this->error('Unknown connector. Available: '.implode(', ', ConnectorRegistry::keys()));

            return self::FAILURE;
        }

        return $this->argument('action') === 'test'
            ? $this->test($tester, $keys)
            : $this->status($settings, $keys);
    }

    private function status(ConnectorSettingsRepository $settings, array $keys): int
    {
        $rows = [];

        foreach ($keys as $key) {
            $resolved = $settings->resolve($key);
            $stored = $settings->setting($key);
            $missing = $settings->missingFields($key);

            $sources = [];
            foreach (ConnectorRegistry::fieldNames($key) as $field) {
                if (blank($resolved[$field] ?? null)) {
                    continue;
                }

                $sources[] = $settings->isInheritedFromEnv($key, $field) ? "{$field}:env" : "{$field}:db";
            }

            $rows[] = [
                $key,
                $resolved['driver'] ?? 'fake',
                $missing === [] ? 'ready' : 'missing '.implode(',', $missing),
                $stored?->last_test_status ?? '—',
                implode(' ', $sources) ?: '—',
            ];
        }

        $this->table(['connector', 'driver', 'state', 'last test', 'value sources'], $rows);
        $this->line('  Configure at /admin/connectors. Database values override .env.');

        return self::SUCCESS;
    }

    private function test(ConnectorTester $tester, array $keys): int
    {
        $failed = 0;

        foreach ($keys as $key) {
            $result = $tester->test($key);
            $failed += $result['ok'] ? 0 : 1;

            $this->line(sprintf(
                '  %s %-8s %s',
                $result['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $key,
                $result['message'],
            ));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveKeys(): array
    {
        $key = $this->argument('key');

        if (! $key || $key === 'all') {
            return ConnectorRegistry::keys();
        }

        return ConnectorRegistry::exists($key) ? [$key] : [];
    }
}
