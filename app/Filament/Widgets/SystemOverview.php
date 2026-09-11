<?php

namespace App\Filament\Widgets;

use App\Chaos\ChaosProfile;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorSettingsRepository;
use App\Models\EvaluationRun;
use App\Models\HealthCheck;
use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Reporting\AccuracyLedger;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SystemOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [
            $this->serviceHealth(),
            $this->openIncidents(),
            $this->agentAccuracy(),
            $this->measuredAccuracy(),
        ];
    }

    private function serviceHealth(): Stat
    {
        $endpoint = MonitoredEndpoint::where('is_active', true)->first();
        $profile = ChaosProfile::current();

        $recent = $endpoint
            ? DB::table('health_checks')
                ->selectRaw("
                    round(percentile_cont(0.95) within group (order by latency_ms)) as p95,
                    round(avg(case when is_error then 1.0 else 0.0 end) * 100)      as error_pct,
                    count(*)                                                        as samples
                ")
                ->where('monitored_endpoint_id', $endpoint->id)
                ->where('checked_at', '>=', now()->subMinutes(5))
                ->first()
            : null;

        // No recent samples means the pinger is not running, which is a different
        // problem from the service being unhealthy and should not read as green.
        if (! $recent || $recent->samples == 0) {
            return Stat::make('Service p95', 'no data')
                ->description('Run php artisan health:ping --watch')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('gray');
        }

        $degraded = ! $profile->isHealthy();

        return Stat::make('Service p95', $recent->p95.'ms')
            ->description($degraded
                ? 'Chaos active: '.$profile->label
                : round($recent->error_pct).'% errors · healthy')
            ->descriptionIcon($degraded ? 'heroicon-m-bolt-slash' : 'heroicon-m-check-circle')
            ->chart($this->latencySparkline($endpoint))
            ->color($degraded ? 'danger' : 'success');
    }

    private function latencySparkline(MonitoredEndpoint $endpoint): array
    {
        return DB::table('health_checks')
            ->selectRaw("round(percentile_cont(0.95) within group (order by latency_ms)) as p95")
            ->where('monitored_endpoint_id', $endpoint->id)
            ->where('checked_at', '>=', now()->subMinutes(30))
            ->groupByRaw("date_trunc('minute', checked_at)")
            ->orderByRaw("date_trunc('minute', checked_at)")
            ->pluck('p95')
            ->map(fn ($v) => (int) $v)
            ->take(-20)
            ->values()
            ->all() ?: [0];
    }

    private function openIncidents(): Stat
    {
        $open = Incident::whereIn('status', ['open', 'investigating'])->count();
        $today = Incident::where('detected_at', '>=', now()->subDay())->count();

        return Stat::make('Open incidents', (string) $open)
            ->description($today.' in the last 24h')
            ->descriptionIcon($open > 0 ? 'heroicon-m-fire' : 'heroicon-m-check-circle')
            ->color($open > 0 ? 'danger' : 'success');
    }

    private function agentAccuracy(): Stat
    {
        $run = EvaluationRun::whereNotNull('finished_at')->latest('started_at')->first();

        if (! $run) {
            return Stat::make('Agent accuracy', 'not measured')
                ->description('Run php artisan eval:run')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('gray');
        }

        $fabricated = $run->evidence_real_rate !== null && $run->evidence_real_rate < 1.0;

        return Stat::make('Root cause hit', number_format($run->root_cause_hit_rate * 100, 0).'%')
            ->description($fabricated
                ? 'fabricated citations present'
                : 'no fabricated citations · '.$run->scenario_count.' scenarios')
            ->descriptionIcon($fabricated ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
            ->color($fabricated ? 'danger' : 'success');
    }

    /**
     * Accuracy from human verdicts, kept separate from the seeded eval figure
     * beside it. One says "capable under known conditions", the other says "was
     * right about a real incident" -- averaging them would blur the distinction
     * that matters most.
     */
    private function measuredAccuracy(): Stat
    {
        $ledger = app(AccuracyLedger::class)->summary();

        if ($ledger['judged'] === 0) {
            return Stat::make('Confirmed by a human', 'no verdicts yet')
                ->description($ledger['awaiting'].' claims awaiting judgement')
                ->descriptionIcon('heroicon-m-hand-thumb-up')
                ->color('gray');
        }

        $rate = number_format($ledger['rate'] * 100, 0).'%';

        return Stat::make('Confirmed by a human', $rate)
            ->description($ledger['confirmed'].' of '.$ledger['judged'].' judged'
                .($ledger['is_meaningful'] ? '' : ' — too few to read much into'))
            ->descriptionIcon($ledger['is_meaningful'] ? 'heroicon-m-hand-thumb-up' : 'heroicon-m-information-circle')
            ->color($ledger['is_meaningful'] ? ($ledger['rate'] >= 0.7 ? 'success' : 'warning') : 'gray');
    }

    private function connectorReadiness(): Stat
    {
        $settings = app(ConnectorSettingsRepository::class);

        $live = collect(ConnectorRegistry::keys())
            ->filter(fn (string $key) => ($settings->resolve($key)['driver'] ?? 'fake') === 'api'
                && $settings->isReady($key))
            ->count();

        $total = count(ConnectorRegistry::keys());

        return Stat::make('Connectors live', "{$live} / {$total}")
            ->description($live === 0 ? 'all using fakes — nothing leaves this machine' : 'writing to real workspaces')
            ->descriptionIcon($live === 0 ? 'heroicon-m-beaker' : 'heroicon-m-signal')
            ->color($live === 0 ? 'gray' : 'warning');
    }
}
