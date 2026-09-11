<?php

namespace App\Connectors\Github;

use App\Connectors\Contracts\GitHubConnector;
use App\Models\Commit;
use App\Models\Deployment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pulls real deployment and commit history into local storage.
 *
 * The agent's tools read from the database rather than calling GitHub directly,
 * so an investigation is reproducible: re-running one cannot silently see
 * different upstream state than the run it is being compared against.
 */
class ApiGitHubConnector implements GitHubConnector
{
    public function sync(Carbon $from, Carbon $to): int
    {
        $repo = (string) config('connectors.github.repo');

        if ($repo === '') {
            return 0;
        }

        $synced = 0;

        foreach ($this->deployments($repo) as $deployment) {
            $createdAt = Carbon::parse($deployment['created_at']);

            if ($createdAt->lt($from) || $createdAt->gt($to)) {
                continue;
            }

            $sha = $deployment['sha'];
            $this->storeCommit($repo, $sha);

            Deployment::updateOrCreate(
                ['sha' => $sha, 'repo' => $repo, 'environment' => $deployment['environment'] ?? 'production'],
                [
                    'ref' => $deployment['ref'] ?? 'main',
                    'author' => $deployment['creator']['login'] ?? null,
                    'deployed_at' => $createdAt,
                    'html_url' => "https://github.com/{$repo}/deployments",
                ],
            );

            $synced++;
        }

        // Repos without the Deployments API in use still have commit history,
        // which is the signal the correlator actually needs.
        if ($synced === 0) {
            $synced = $this->syncFromCommits($repo, $from, $to);
        }

        return $synced;
    }

    public function commit(string $sha): ?array
    {
        $repo = (string) config('connectors.github.repo');
        $response = $this->request()->get("/repos/{$repo}/commits/{$sha}");

        return $response->successful() ? $response->json() : null;
    }

    public function isConfigured(): bool
    {
        return filled(config('connectors.github.token')) && filled(config('connectors.github.repo'));
    }

    private function deployments(string $repo): array
    {
        $response = $this->request()->get("/repos/{$repo}/deployments", ['per_page' => 50]);

        return $response->successful() ? $response->json() : [];
    }

    private function syncFromCommits(string $repo, Carbon $from, Carbon $to): int
    {
        $response = $this->request()->get("/repos/{$repo}/commits", [
            'since' => $from->toIso8601String(),
            'until' => $to->toIso8601String(),
            'per_page' => 30,
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json() as $entry) {
            $sha = $entry['sha'];
            $committedAt = Carbon::parse($entry['commit']['committer']['date']);

            $this->storeCommit($repo, $sha);

            Deployment::updateOrCreate(
                ['sha' => $sha, 'repo' => $repo, 'environment' => 'production'],
                [
                    'ref' => 'main',
                    'author' => $entry['commit']['author']['name'] ?? null,
                    'deployed_at' => $committedAt,
                    'html_url' => $entry['html_url'] ?? null,
                ],
            );

            $count++;
        }

        return $count;
    }

    private function storeCommit(string $repo, string $sha): void
    {
        if (Commit::where('sha', $sha)->exists()) {
            return;
        }

        $detail = $this->commit($sha);

        if ($detail === null) {
            return;
        }

        $files = collect($detail['files'] ?? []);

        Commit::create([
            'sha' => $sha,
            'repo' => $repo,
            'message' => $detail['commit']['message'] ?? '',
            'author' => $detail['commit']['author']['name'] ?? null,
            'committed_at' => Carbon::parse($detail['commit']['committer']['date'] ?? now()),
            'changed_files' => $files->pluck('filename')->all(),
            'additions' => $detail['stats']['additions'] ?? 0,
            'deletions' => $detail['stats']['deletions'] ?? 0,
            'diff_summary' => $files
                ->take(5)
                ->map(fn ($f) => "{$f['filename']} (+{$f['additions']}/-{$f['deletions']})")
                ->implode('; '),
            // GitHub returns a per-file patch; cap it so one large commit cannot
            // crowd the model's context out.
            'patch' => $files
                ->take(5)
                ->map(fn ($f) => "--- a/{$f['filename']}\n+++ b/{$f['filename']}\n".($f['patch'] ?? ''))
                ->implode("\n\n"),
            'html_url' => $detail['html_url'] ?? null,
        ]);
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('connectors.github.token'))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->baseUrl(config('connectors.github.api_url'))
            ->timeout(30);
    }
}
