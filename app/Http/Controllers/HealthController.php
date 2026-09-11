<?php

namespace App\Http\Controllers;

use App\Chaos\ChaosProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * The service under observation.
 *
 * Real dependency checks, plus a chaos profile that injects latency and errors
 * so a degradation can be produced on demand. `deploy_sha` is echoed back on
 * every response, which is what lets each latency sample be attributed to the
 * build that was live when it was taken -- correlation on recorded fact rather
 * than on timestamp guesswork.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $startedAt = microtime(true);
        $profile = ChaosProfile::current();

        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        // The injected sleep: this is the API "sleeping" under a chaos scenario.
        if (($sleepMs = $profile->sampleLatencyMs()) > 0) {
            usleep($sleepMs * 1000);
        }

        $dependenciesHealthy = collect($checks)->every(fn ($c) => $c['ok'] === true);
        $chaosFailure = $profile->shouldFail();
        $healthy = $dependenciesHealthy && ! $chaosFailure;

        $body = [
            'status' => $healthy ? 'ok' : 'degraded',
            'scenario' => $profile->scenarioKey,
            'deploy_sha' => $profile->deploySha,
            'checks' => $checks,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($chaosFailure) {
            $body['error'] = 'upstream_unavailable';
        }

        return response()->json($body, $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');

            return ['ok' => true, 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();

            return ['ok' => true, 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $depth = (int) Redis::llen('queues:default');

            return ['ok' => $depth < 1000, 'depth' => $depth];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
