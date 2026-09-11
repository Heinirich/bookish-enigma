<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\Incident;
use App\Models\MonitoredEndpoint;
use App\Models\ScheduleSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleSettingTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $attributes = []): ScheduleSetting
    {
        $settings = ScheduleSetting::current();
        $settings->update($attributes);

        return $settings->fresh();
    }

    public function test_it_defers_a_ping_until_the_interval_has_elapsed(): void
    {
        $settings = $this->settings([
            'ping_interval_seconds' => 30,
            'last_ping_at' => now(),
        ]);

        $this->assertFalse($settings->shouldPingNow());
        $this->assertFalse($settings->shouldPingNow(now()->addSeconds(29)));
        $this->assertTrue($settings->shouldPingNow(now()->addSeconds(31)));
    }

    public function test_pausing_monitoring_stops_polling_and_detection(): void
    {
        $settings = $this->settings(['monitoring_enabled' => false, 'last_ping_at' => null]);

        $this->assertFalse($settings->shouldPingNow());
        $this->assertFalse($settings->shouldDetectNow());
    }

    /**
     * Each run is tens of seconds of local inference. Without a ceiling a
     * flapping endpoint queues them faster than the worker can drain them.
     */
    public function test_it_refuses_to_exceed_the_hourly_cap(): void
    {
        $settings = $this->settings(['auto_investigate' => true, 'max_investigations_per_hour' => 2]);

        $incident = $this->incident();
        Investigation::create(['incident_id' => $incident->id, 'model' => 'test']);

        [$allowed] = $settings->mayInvestigate();
        $this->assertTrue($allowed);

        Investigation::create(['incident_id' => $incident->id, 'model' => 'test']);

        [$allowed, $reason] = $settings->mayInvestigate();
        $this->assertFalse($allowed);
        $this->assertStringContainsString('hourly cap', $reason);
    }

    public function test_investigations_older_than_an_hour_do_not_count_towards_the_cap(): void
    {
        $settings = $this->settings(['auto_investigate' => true, 'max_investigations_per_hour' => 1]);
        $incident = $this->incident();

        Investigation::create(['incident_id' => $incident->id, 'model' => 'test'])
            ->forceFill(['created_at' => now()->subHours(2)])->save();

        [$allowed] = $settings->mayInvestigate();

        $this->assertTrue($allowed, 'The cap is a rolling hour, not a lifetime total.');
    }

    public function test_turning_off_auto_investigate_blocks_automated_runs(): void
    {
        [$allowed, $reason] = $this->settings(['auto_investigate' => false])->mayInvestigate();

        $this->assertFalse($allowed);
        $this->assertStringContainsString('auto-investigate is off', $reason);
    }

    public function test_a_quiet_window_that_wraps_midnight_is_handled(): void
    {
        $settings = $this->settings(['quiet_hours_start' => 22, 'quiet_hours_end' => 6]);

        $this->assertTrue($settings->inQuietHours(now()->setTime(23, 0)));
        $this->assertTrue($settings->inQuietHours(now()->setTime(3, 0)));
        $this->assertFalse($settings->inQuietHours(now()->setTime(12, 0)));
    }

    public function test_a_quiet_window_inside_one_day_is_handled(): void
    {
        $settings = $this->settings(['quiet_hours_start' => 9, 'quiet_hours_end' => 17]);

        $this->assertTrue($settings->inQuietHours(now()->setTime(12, 0)));
        $this->assertFalse($settings->inQuietHours(now()->setTime(20, 0)));
        $this->assertFalse($settings->inQuietHours(now()->setTime(17, 0)), 'The end hour is exclusive.');
    }

    public function test_no_quiet_hours_configured_never_suppresses(): void
    {
        $settings = $this->settings(['quiet_hours_start' => null, 'quiet_hours_end' => null]);

        $this->assertFalse($settings->inQuietHours(now()->setTime(3, 0)));
    }

    public function test_the_scheduled_detector_skips_when_not_yet_due(): void
    {
        $this->settings(['detect_interval_minutes' => 10, 'last_detect_at' => now()]);

        $this->artisan('health:detect --scheduled --no-dispatch')
            ->doesntExpectOutputToContain('payments-api')
            ->assertSuccessful();
    }

    /** Regression: firstOrCreate does not read database defaults back. */
    public function test_a_fresh_install_has_usable_defaults_not_nulls(): void
    {
        ScheduleSetting::query()->delete();

        $settings = ScheduleSetting::current();

        $this->assertSame(15, $settings->incident_cooldown_minutes);
        $this->assertSame(10, $settings->ping_interval_seconds);
        $this->assertSame(6, $settings->max_investigations_per_hour);
        $this->assertTrue($settings->monitoring_enabled);
        $this->assertTrue($settings->auto_investigate);
    }

    public function test_the_detector_reads_its_cooldown_from_the_database(): void
    {
        $this->settings(['incident_cooldown_minutes' => 45]);

        $method = new \ReflectionMethod(\App\Monitoring\IncidentDetector::class, 'config');
        $method->setAccessible(true);

        $this->assertSame(
            45,
            $method->invoke(app(\App\Monitoring\IncidentDetector::class))['cooldown_minutes'],
            'A cooldown changed in the UI must apply without restarting the worker.',
        );
    }

    private function incident(): Incident
    {
        $endpoint = MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api-'.uniqid(),
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);

        return Incident::create([
            'reference' => 'INC-'.strtoupper(uniqid()), 'title' => 'test',
            'monitored_endpoint_id' => $endpoint->id, 'detected_at' => now(),
            'window_start' => now()->subMinutes(30), 'window_end' => now(),
        ]);
    }
}
