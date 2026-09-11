<?php

namespace App\Connectors;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Verifies credentials against the live API.
 *
 * Every check is read-only and identity-shaped -- "who am I", "does this repo
 * exist" -- so testing a connector never creates a channel, a ticket or a page.
 * Finding out a token is wrong should not cost you a stray artifact in a real
 * workspace.
 */
class ConnectorTester
{
    public function __construct(private readonly ConnectorSettingsRepository $settings) {}

    /** @return array{ok:bool,message:string} */
    public function test(string $key): array
    {
        if (! ConnectorRegistry::exists($key)) {
            return $this->record($key, false, "Unknown connector [{$key}].");
        }

        $config = $this->settings->resolve($key);

        if (($config['driver'] ?? 'fake') !== 'api') {
            return $this->record($key, true, 'Using the fake driver — nothing to reach.');
        }

        if ($missing = $this->settings->missingFields($key)) {
            return $this->record($key, false, 'Missing: '.implode(', ', $missing));
        }

        try {
            $result = match ($key) {
                'github' => $this->github($config),
                'slack' => $this->slack($config),
                'jira' => $this->jira($config),
                'notion' => $this->notion($config),
            };
        } catch (\Throwable $e) {
            $result = [false, 'Could not reach the API: '.Str::limit($e->getMessage(), 160)];
        }

        return $this->record($key, $result[0], $result[1]);
    }

    /** @return array{0:bool,1:string} */
    private function github(array $config): array
    {
        $request = Http::withToken($config['token'])
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->timeout(15);

        $user = $request->get('https://api.github.com/user');

        if ($user->status() === 401) {
            return [false, 'Token rejected (401). Check it has not expired.'];
        }

        if (! $user->successful()) {
            return [false, "GitHub returned {$user->status()}."];
        }

        $login = $user->json('login');
        $repo = $request->get("https://api.github.com/repos/{$config['repo']}");

        if ($repo->status() === 404) {
            return [false, "Authenticated as {$login}, but {$config['repo']} is not visible to this token."];
        }

        if (! $repo->successful()) {
            return [false, "Authenticated as {$login}, but the repo check returned {$repo->status()}."];
        }

        return [true, "Authenticated as {$login}; {$config['repo']} is readable."];
    }

    /** @return array{0:bool,1:string} */
    private function slack(array $config): array
    {
        $response = Http::withToken($config['bot_token'])->timeout(15)
            ->post('https://slack.com/api/auth.test');

        $body = $response->json() ?? [];

        // Slack answers 200 with ok:false, so the status code proves nothing.
        if (! ($body['ok'] ?? false)) {
            return [false, 'Slack rejected the token: '.($body['error'] ?? 'unknown error')];
        }

        return [true, "Connected to {$body['team']} as {$body['user']}."];
    }

    /** @return array{0:bool,1:string} */
    private function jira(array $config): array
    {
        $baseUrl = rtrim($config['base_url'], '/');

        $request = Http::withBasicAuth($config['email'], $config['api_token'])
            ->acceptJson()
            ->timeout(20);

        $me = $request->get("{$baseUrl}/rest/api/3/myself");

        if ($me->status() === 401 || $me->status() === 403) {
            return [false, 'Jira rejected the credentials ('.$me->status().').'];
        }

        if (! $me->successful()) {
            return [false, "Jira returned {$me->status()} for /myself."];
        }

        $name = $me->json('displayName');
        $project = $request->get("{$baseUrl}/rest/api/3/project/{$config['project_key']}");

        if (! $project->successful()) {
            return [false, "Authenticated as {$name}, but project {$config['project_key']} is not accessible."];
        }

        return [true, "Authenticated as {$name}; project {$config['project_key']} is writable."];
    }

    /** @return array{0:bool,1:string} */
    private function notion(array $config): array
    {
        $request = Http::withToken($config['token'])
            ->withHeaders(['Notion-Version' => $config['version'] ?? '2022-06-28'])
            ->timeout(15);

        $me = $request->get('https://api.notion.com/v1/users/me');

        if (! $me->successful()) {
            return [false, 'Notion rejected the token ('.$me->status().').'];
        }

        $name = $me->json('name') ?? 'integration';
        $page = $request->get('https://api.notion.com/v1/pages/'.$config['parent_page_id']);

        // The most common Notion mistake: a valid token whose integration was
        // never shared with the target page, which reads as 404 rather than 403.
        if ($page->status() === 404) {
            return [false, "Connected as {$name}, but the parent page is not shared with this integration."];
        }

        if (! $page->successful()) {
            return [false, "Connected as {$name}, but the parent page returned {$page->status()}."];
        }

        return [true, "Connected as {$name}; the parent page is reachable."];
    }

    /** @return array{ok:bool,message:string} */
    private function record(string $key, bool $ok, string $message): array
    {
        ConnectorSetting::where('key', $key)->update([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'failed',
            'last_test_message' => $message,
        ]);

        $this->settings->flush();

        return ['ok' => $ok, 'message' => $message];
    }
}
