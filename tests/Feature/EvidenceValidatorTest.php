<?php

namespace Tests\Feature;

use App\Agent\EvidenceLedger;
use App\Agent\EvidenceValidator;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\MonitoredEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The project's central claim is that a conclusion can be checked against the
 * source it came from. These tests pin the two ways that claim can be violated.
 */
class EvidenceValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Investigation $investigation;

    private EvidenceValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api',
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);

        $incident = Incident::create([
            'reference' => 'INC-TEST01', 'title' => 'latency spike',
            'monitored_endpoint_id' => $endpoint->id, 'detected_at' => now(),
            'window_start' => now()->subMinutes(30), 'window_end' => now(),
        ]);

        $this->investigation = Investigation::create([
            'incident_id' => $incident->id, 'model' => 'test-model',
        ]);

        $ledger = app(EvidenceLedger::class);

        $ledger->record($this->investigation, 'healthz', 'metric_series',
            'p95 rose from 39ms to 1012ms', ['p95_before_ms' => 39, 'p95_after_ms' => 1012, 'ratio' => 25.95]);

        $ledger->record($this->investigation, 'github', 'diff',
            'Commit ffa73e9 changed the order history query', ['sha' => 'ffa73e9', 'additions' => 84]);

        $this->validator = app(EvidenceValidator::class);
    }

    public function test_it_accepts_a_claim_citing_real_evidence(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'Deploy ffa73e9 pushed p95 to 1012ms.',
            'evidence_ids' => ['EV-1', 'EV-2'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertTrue($report->passed());
        $this->assertSame([], $report->fabricated);
        $this->assertEquals(1.0, $report->groundingScore);
    }

    /** The exact failure observed from the live model before any guardrail existed. */
    public function test_it_rejects_descriptive_identifiers_the_model_invented(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'Latency rose sharply.',
            'evidence_ids' => ['latency_log_abc123_01', 'monitoring_dashboard_abc123_03'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertFalse($report->passed());
        $this->assertCount(2, $report->fabricated);
        $this->assertSame([], $report->valid);
    }

    public function test_it_rejects_an_id_that_looks_real_but_was_never_issued(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'The deploy is at fault.',
            'evidence_ids' => ['EV-1', 'EV-99'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertFalse($report->passed());
        $this->assertSame(['EV-99'], $report->fabricated);
        $this->assertSame(['EV-1'], $report->valid);
    }

    /**
     * Regression: unit suffixes ("8400ms", "212x") once defeated token extraction
     * entirely, so wholly invented figures scored a vacuous grounding of 1.00.
     */
    public function test_it_catches_invented_figures_written_with_unit_suffixes(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'p95 climbed to 8400ms, a 212x regression.',
            'evidence_ids' => ['EV-1'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertEqualsCanonicalizing(['8400', '212'], $report->ungroundedTokens);
        $this->assertEquals(0.0, $report->groundingScore);
        $this->assertFalse($report->passed(), 'A statement with no grounded figures is not checkable.');
    }

    public function test_it_allows_sensible_rounding_of_a_real_figure(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'p95 rose roughly 26x after the deploy.',
            'evidence_ids' => ['EV-1'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertSame([], $report->ungroundedTokens);
        $this->assertTrue($report->passed());
    }

    /** A substring hit inside a larger number must not count as grounding. */
    public function test_it_does_not_ground_a_figure_by_substring_coincidence(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'The service returned 101 errors during the window.',
            'evidence_ids' => ['EV-1'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertContains('101', $report->ungroundedTokens,
            '101 appears inside 1012 but is not itself a figure in the evidence.');
    }

    public function test_partial_grounding_is_scored_but_not_fatal(): void
    {
        $report = $this->validator->validate($this->investigation, [
            'statement' => 'p95 reached 1012ms after 84 added lines, affecting 4500 requests.',
            'evidence_ids' => ['EV-1', 'EV-2'],
            'contradicting_evidence_ids' => [],
        ]);

        $this->assertContains('4500', $report->ungroundedTokens);
        $this->assertGreaterThan(0.0, $report->groundingScore);
        $this->assertTrue($report->passed());
    }
}
