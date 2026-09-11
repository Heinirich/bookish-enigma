<?php

namespace Tests\Feature;

use App\Agent\Correlator;
use App\Chaos\ScenarioActivator;
use App\Chaos\ScenarioLibrary;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Monitoring\IncidentDetector;
use App\Monitoring\SyntheticHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The correlator is what makes a 4B model viable here: it establishes the
 * arithmetic so the model is never asked to do it. If it stops ranking the true
 * cause first, the whole approach degrades to guesswork.
 */
class CorrelatorTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api',
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);
    }

    public static function scenarioProvider(): array
    {
        return array_map(fn ($key) => [$key], ScenarioLibrary::keys());
    }

    #[DataProvider('scenarioProvider')]
    public function test_it_ranks_the_seeded_cause_above_its_decoys(string $scenarioKey): void
    {
        $incident = $this->stage($scenarioKey);

        $this->assertNotNull($incident, "No incident detected for [{$scenarioKey}].");

        $correlator = app(Correlator::class);
        $series = $correlator->series($incident);
        $changepoint = $correlator->changepoint($incident, $series);

        $this->assertNotNull($changepoint, "No changepoint found for [{$scenarioKey}].");

        $candidates = $correlator->candidates($incident, Carbon::parse($changepoint['at']));
        $this->assertNotEmpty($candidates);

        /*
        | ambiguous-tie is built so the correlator cannot win: the decoy ships in
        | the same minute and touches the same request path, so timing and path
        | scoring are identical by construction. Resolving it requires reading the
        | code, which is the model's job. Asserting a tie here keeps the scenario
        | honest -- if a future scoring change let the correlator separate them,
        | the scenario would have stopped testing what it claims to.
        */
        if ($scenarioKey === 'ambiguous-tie') {
            $margin = $candidates[0]['correlation_score'] - ($candidates[1]['correlation_score'] ?? 0);

            $this->assertLessThan(
                0.05, abs($margin),
                'ambiguous-tie must stay undecidable on timing and path alone.',
            );

            return;
        }

        // Scenarios that declare no deploy is to blame invert the expectation:
        // the correlator should find nothing compelling rather than pick a winner.
        if (ScenarioLibrary::get($scenarioKey)['expects_no_deploy_cause'] ?? false) {
            $this->assertLessThan(
                0.45,
                $candidates[0]['correlation_score'],
                "[{$scenarioKey}] correlated a deploy strongly when none was responsible.",
            );

            return;
        }

        $top = Deployment::find($candidates[0]['deployment_id']);

        $this->assertTrue(
            $top->is_seeded_cause,
            "[{$scenarioKey}] ranked decoy {$candidates[0]['sha']} above the true cause.",
        );
    }

    /**
     * Regression: minute bucketing reports the first *fully* degraded minute, so
     * a deploy that shipped mid-bucket appeared to land after the break it caused
     * and was scored as if it were exonerated.
     */
    public function test_the_changepoint_is_refined_below_minute_resolution(): void
    {
        $incident = $this->stage('slow-query');
        $correlator = app(Correlator::class);

        $changepoint = $correlator->changepoint($incident, $correlator->series($incident));

        $this->assertNotNull($changepoint);
        $this->assertArrayHasKey('bucket_at', $changepoint);

        $culprit = Deployment::where('is_seeded_cause', true)->firstOrFail();
        $driftSeconds = abs(Carbon::parse($changepoint['at'])->diffInSeconds($culprit->deployed_at));

        $this->assertLessThanOrEqual(
            90, $driftSeconds,
            'Refined changepoint should sit close to the deploy that caused it.',
        );
    }

    public function test_a_steady_series_produces_no_changepoint(): void
    {
        $now = now();

        for ($i = 200; $i >= 0; $i--) {
            $this->endpoint->healthChecks()->create([
                'checked_at' => $now->copy()->subSeconds($i * 10),
                'status_code' => 200,
                'latency_ms' => random_int(30, 45),
                'is_error' => false,
            ]);
        }

        $incident = Incident::create([
            'reference' => 'INC-STEADY', 'title' => 'nothing wrong',
            'monitored_endpoint_id' => $this->endpoint->id, 'detected_at' => $now,
            'window_start' => $now->copy()->subMinutes(30), 'window_end' => $now,
        ]);

        $correlator = app(Correlator::class);

        $this->assertNull($correlator->changepoint($incident, $correlator->series($incident)));
    }

    private function stage(string $scenarioKey): ?Incident
    {
        app(ScenarioActivator::class)->activate($scenarioKey);
        app(SyntheticHistory::class)->generate($this->endpoint, $scenarioKey, now());

        return app(IncidentDetector::class)->detect($this->endpoint, now());
    }
}
