<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Investigation;
use App\Models\MonitoredEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The slash command is the one publicly reachable write path in the app, so its
 * authentication is the part worth pinning down.
 */
class SlackCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        MonitoredEndpoint::create([
            'name' => 'Payments API', 'slug' => 'payments-api',
            'url' => 'http://localhost/healthz', 'service' => 'payments-api',
        ]);

        config(['connectors.slack.signing_secret' => self::SECRET]);
        Queue::fake();
    }

    /** @param array<string,string> $payload */
    private function slashCommand(array $payload, ?string $signature = null, ?string $timestamp = null)
    {
        $timestamp ??= (string) time();
        $body = http_build_query($payload);

        $signature ??= 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET);

        return $this->call(
            'POST', '/slack/commands/investigate', $payload, [], [], [
                'HTTP_X-Slack-Request-Timestamp' => $timestamp,
                'HTTP_X-Slack-Signature' => $signature,
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            $body,
        );
    }

    public function test_a_correctly_signed_command_opens_an_incident(): void
    {
        $response = $this->slashCommand([
            'text' => 'the payment API feels slow',
            'user_name' => 'heinrich',
            'channel_id' => 'C0EXAMPLE',
        ]);

        $response->assertOk()->assertJsonPath('response_type', 'in_channel');

        $incident = Incident::firstOrFail();

        $this->assertSame('manual', $incident->trigger);
        $this->assertSame('C0EXAMPLE', $incident->slack_channel_id);
        $this->assertStringContainsString('the payment API feels slow', $incident->prompt);
        $this->assertStringContainsString('heinrich', $incident->prompt);
        $this->assertSame(1, Investigation::count());
    }

    public function test_it_rejects_a_forged_signature(): void
    {
        $this->slashCommand(['text' => 'anything'], signature: 'v0=deadbeef')
            ->assertStatus(401);

        $this->assertSame(0, Incident::count());
    }

    /** An old timestamp is a replayed request, even with a signature that once verified. */
    public function test_it_rejects_a_replayed_request(): void
    {
        $stale = (string) (time() - 3600);
        $body = http_build_query(['text' => 'replay me']);

        $this->slashCommand(
            ['text' => 'replay me'],
            signature: 'v0='.hash_hmac('sha256', "v0:{$stale}:{$body}", self::SECRET),
            timestamp: $stale,
        )->assertStatus(401);

        $this->assertSame(0, Incident::count());
    }

    /** With no secret configured the endpoint must be closed, not open. */
    public function test_it_refuses_everything_when_no_signing_secret_is_set(): void
    {
        config(['connectors.slack.signing_secret' => null]);

        $this->slashCommand(['text' => 'anything'])->assertStatus(401);

        $this->assertSame(0, Incident::count());
    }

    public function test_an_empty_command_explains_itself_without_opening_an_incident(): void
    {
        $this->slashCommand(['text' => '   '])
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertSame(0, Incident::count());
    }
}
