<?php

namespace Tests\Feature;

use App\Agent\Investigator;
use App\Models\Hypothesis;
use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationPdfTest extends TestCase
{
    use RefreshDatabase;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api',
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);

        $this->incident = Incident::create([
            'reference' => 'INC-PDF001', 'title' => 'p95 latency up 25x',
            'monitored_endpoint_id' => $endpoint->id, 'detected_at' => now(),
            'window_start' => now()->subMinutes(30), 'window_end' => now(),
            'detection_signal' => ['latency_ratio' => 25.0, 'reason' => 'latency_p95'],
        ]);

        $investigation = Investigator::open($this->incident);
        $investigation->update(['status' => 'completed', 'duration_ms' => 24000]);

        Hypothesis::create([
            'investigation_id' => $investigation->id,
            'statement' => 'Deploy abc1234 added an unindexed join.',
            'confidence' => 0.91, 'rank' => 1, 'status' => 'accepted',
            'grounding_score' => 1.0,
            'validation_report' => ['cited' => ['EV-1'], 'fabricated' => []],
        ]);
    }

    /** The report carries commit messages and diffs, so it must not be public. */
    public function test_it_redirects_guests_to_the_login_page(): void
    {
        $this->get("/incidents/{$this->incident->id}/report.pdf")
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_it_returns_a_pdf_to_an_authenticated_user(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get("/incidents/{$this->incident->id}/report.pdf");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload();

        $this->assertStringStartsWith('%PDF-', $response->getContent(),
            'The body must be a real PDF, not an HTML error page rendered with a PDF content type.');
    }

    public function test_it_404s_for_an_incident_with_no_investigation(): void
    {
        $bare = Incident::create([
            'reference' => 'INC-EMPTY1', 'title' => 'nothing here',
            'detected_at' => now(), 'window_start' => now()->subHour(), 'window_end' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/incidents/{$bare->id}/report.pdf")
            ->assertNotFound();
    }
}
