<?php

namespace Tests\Feature;

use App\Models\HealthCheck;
use App\Models\MonitoredEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthPingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api',
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);
    }

    public function test_it_records_a_normal_sample(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'deploy_sha' => 'abc1234'], 200)]);

        $this->artisan('health:ping')->assertSuccessful();

        $check = HealthCheck::firstOrFail();

        $this->assertSame(200, $check->status_code);
        $this->assertFalse($check->is_error);
        $this->assertSame('abc1234', $check->deploy_sha);
    }

    /**
     * Regression: two real samples recorded 998s and 301s against a 15s timeout.
     *
     * Wall clock keeps advancing while the host is suspended, so a request in
     * flight when the laptop sleeps returns a duration that has nothing to do
     * with the service. Left unclamped they flattened every latency chart, and
     * one inside a baseline window would lift p95 far enough that a genuine
     * degradation could not trip the detector.
     */
    public function test_a_measurement_longer_than_any_possible_timeout_is_clamped(): void
    {
        Http::fake(function () {
            // Simulate the host having been suspended mid-request.
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 998635 milliseconds',
            );
        });

        $endpoint = MonitoredEndpoint::firstOrFail();

        // Drive the private ping directly so the elapsed time can be forced.
        $command = new \App\Console\Commands\HealthPingCommand();
        $method = new \ReflectionMethod($command, 'ping');
        $method->setAccessible(true);

        $check = $method->invoke($command, $endpoint);

        $this->assertTrue($check->is_error);
        $this->assertLessThanOrEqual(
            30000, $check->latency_ms,
            'A suspend artefact must never enter the metrics at its raw wall-clock value.',
        );
    }

    public function test_an_unexpected_status_counts_as_an_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'degraded'], 503)]);

        $this->artisan('health:ping')->assertSuccessful();

        $check = HealthCheck::firstOrFail();

        $this->assertSame(503, $check->status_code);
        $this->assertTrue($check->is_error);
    }
}
