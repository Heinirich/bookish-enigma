<?php

namespace App\Connectors\Slack;

use App\Connectors\Contracts\SlackConnector;
use Illuminate\Support\Str;

/**
 * Records what would have been sent. Keeps the full investigation loop -- and
 * every eval run -- working without touching a real workspace.
 */
class FakeSlackConnector implements SlackConnector
{
    public function createChannel(string $name, string $purpose): array
    {
        $id = 'C'.strtoupper(Str::random(9));

        return ['id' => $id, 'name' => $name, 'url' => "https://slack.example/archives/{$id}", 'fake' => true];
    }

    public function postMessage(string $channelId, string $text): array
    {
        $ts = sprintf('%d.%06d', now()->timestamp, random_int(0, 999999));

        return [
            'ts' => $ts,
            'url' => "https://slack.example/archives/{$channelId}/p".str_replace('.', '', $ts),
            'fake' => true,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
