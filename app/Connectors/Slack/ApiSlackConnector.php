<?php

namespace App\Connectors\Slack;

use App\Connectors\Contracts\SlackConnector;
use Illuminate\Support\Facades\Http;

class ApiSlackConnector implements SlackConnector
{
    public function createChannel(string $name, string $purpose): array
    {
        $response = $this->call('conversations.create', ['name' => $name, 'is_private' => false]);

        $channel = $response['channel'];

        // Non-fatal: the channel exists and is usable even if the purpose is rejected.
        if ($purpose !== '') {
            try {
                $this->call('conversations.setPurpose', [
                    'channel' => $channel['id'],
                    'purpose' => mb_substr($purpose, 0, 250),
                ]);
            } catch (\Throwable) {
            }
        }

        return [
            'id' => $channel['id'],
            'name' => $channel['name'],
            'url' => "https://slack.com/app_redirect?channel={$channel['id']}",
        ];
    }

    public function postMessage(string $channelId, string $text): array
    {
        $response = $this->call('chat.postMessage', [
            'channel' => $channelId,
            'text' => $text,
            'mrkdwn' => true,
        ]);

        return [
            'ts' => $response['ts'],
            'url' => "https://slack.com/app_redirect?channel={$channelId}",
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('connectors.slack.bot_token'));
    }

    private function call(string $method, array $payload): array
    {
        $response = Http::withToken(config('connectors.slack.bot_token'))
            ->asJson()
            ->timeout(20)
            ->post(config('connectors.slack.api_url')."/{$method}", $payload);

        $body = $response->json() ?? [];

        // Slack answers 200 with ok:false, so the HTTP status alone proves nothing.
        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new \RuntimeException(
                "Slack {$method} failed: ".($body['error'] ?? $response->status()),
            );
        }

        return $body;
    }
}
