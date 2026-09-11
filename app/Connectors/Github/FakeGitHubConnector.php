<?php

namespace App\Connectors\Github;

use App\Connectors\Contracts\GitHubConnector;
use App\Models\Commit;
use Illuminate\Support\Carbon;

/**
 * Deployment history in fake mode comes from the seeded scenarios, which the
 * activator has already written. Syncing is therefore a no-op rather than a
 * fabrication -- inventing extra deploys here would corrupt eval ground truth.
 */
class FakeGitHubConnector implements GitHubConnector
{
    public function sync(Carbon $from, Carbon $to): int
    {
        return 0;
    }

    public function commit(string $sha): ?array
    {
        $commit = Commit::where('sha', 'like', $sha.'%')->first();

        return $commit?->only([
            'sha', 'message', 'author', 'changed_files', 'additions', 'deletions', 'diff_summary', 'patch',
        ]);
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
