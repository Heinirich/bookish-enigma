<?php

namespace App\Http\Controllers;

use App\Agent\Investigator;
use App\Jobs\RunInvestigation;
use App\Models\MonitoredEndpoint;
use App\Monitoring\ManualIncidentOpener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inbound Slack: /investigate <what is wrong>
 *
 * The rest of the Slack integration is outbound — the agent posts a summary
 * after the fact. This is the direction that matters operationally: someone says
 * "payments feels slow" in the channel where they already are, and the
 * investigation starts there rather than in a dashboard nobody has open.
 *
 * Slack requires a response within 3 seconds, so this acknowledges immediately
 * and lets the queued investigation post its own findings when it finishes.
 */
class SlackCommandController extends Controller
{
    public function __invoke(Request $request, ManualIncidentOpener $opener): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Rejected a Slack command with an invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['text' => 'Signature verification failed.'], 401);
        }

        $prompt = trim((string) $request->input('text'));
        $user = $request->input('user_name', 'someone');

        if ($prompt === '') {
            return $this->ephemeral(
                "Tell me what to look into, for example:\n"
                .'`/investigate the payment API feels slow`'
            );
        }

        $endpoint = MonitoredEndpoint::where('is_active', true)->first();

        if (! $endpoint) {
            return $this->ephemeral('No monitored service is configured yet.');
        }

        $incident = $opener->open(prompt: $prompt, endpoint: $endpoint);
        $incident->update([
            'prompt' => $prompt." (requested by {$user} in Slack)",
            'slack_channel_id' => $request->input('channel_id'),
        ]);

        $investigation = Investigator::open($incident);
        $incident->update(['status' => 'investigating']);

        RunInvestigation::dispatch($incident, $investigation->id);

        return response()->json([
            // in_channel so the rest of the channel sees an investigation started.
            'response_type' => 'in_channel',
            'text' => "*{$incident->reference}* — investigating: _{$prompt}_\n"
                .'I will post the findings here, with the evidence behind each one. '
                .'Anything I want to write to Jira or Notion waits for approval.',
        ]);
    }

    private function ephemeral(string $text): JsonResponse
    {
        return response()->json(['response_type' => 'ephemeral', 'text' => $text]);
    }

    /**
     * Slack signs every request with a timestamp and an HMAC over the raw body.
     *
     * Without this the endpoint is an unauthenticated trigger for expensive work
     * that writes to real systems — anyone who learns the URL could queue
     * investigations.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = config('connectors.slack.signing_secret');

        // No secret configured means the endpoint is not ready to be public.
        if (blank($secret)) {
            return false;
        }

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (blank($timestamp) || blank($signature)) {
            return false;
        }

        // Reject anything old enough to be a replay.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
