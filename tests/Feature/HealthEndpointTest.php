<?php

namespace Tests\Feature;

use App\Chaos\ChaosProfile;
use App\Chaos\ScenarioActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ChaosProfile::reset();
    }

    public function test_it_reports_healthy_with_dependency_checks(): void
    {
        $response = $this->getJson('/healthz');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('scenario', null)
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonStructure(['status', 'deploy_sha', 'checks', 'latency_ms', 'timestamp']);
    }

    public function test_it_echoes_the_live_deploy_sha_once_a_scenario_is_active(): void
    {
        $deployment = app(ScenarioActivator::class)->activate('slow-query');

        $this->getJson('/healthz')
            ->assertJsonPath('scenario', 'slow-query')
            ->assertJsonPath('deploy_sha', $deployment->sha);
    }

    /** Attribution depends on this: a sample is only useful if it names the build that served it. */
    public function test_a_total_outage_scenario_returns_503(): void
    {
        app(ScenarioActivator::class)->activate('hard-down');

        $this->getJson('/healthz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('error', 'upstream_unavailable');
    }

    public function test_the_injected_latency_actually_delays_the_response(): void
    {
        app(ScenarioActivator::class)->activate('slow-query');

        $startedAt = microtime(true);
        $this->getJson('/healthz');
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertGreaterThan(700, $elapsedMs, 'Chaos profile should slow the endpoint measurably.');
    }
}
