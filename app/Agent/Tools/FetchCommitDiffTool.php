<?php

namespace App\Agent\Tools;

use App\Models\Commit;
use App\Models\Investigation;

class FetchCommitDiffTool implements AgentTool
{
    public function name(): string
    {
        return 'fetch_commit_diff';
    }

    public function description(): string
    {
        return 'Get the actual changed lines for one commit, plus its files and line counts. '
            .'Read the code to judge whether the change could produce the observed symptom — '
            .'do not assume a deployment is innocent because its message sounds harmless.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sha'],
            'properties' => [
                'sha' => [
                    'type' => 'string',
                    'description' => 'Short or full commit SHA, exactly as it appears in the evidence.',
                ],
            ],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function execute(Investigation $investigation, array $arguments): ToolResult
    {
        $sha = trim((string) ($arguments['sha'] ?? ''));

        $commit = Commit::where('sha', $sha)
            ->orWhere('sha', 'like', $sha.'%')
            ->first();

        if (! $commit) {
            return ToolResult::empty("No commit found matching '{$sha}'.", 'github', 'diff');
        }

        $payload = [
            'sha' => substr($commit->sha, 0, 7),
            'message' => $commit->message,
            'author' => $commit->author,
            'committed_at' => $commit->committed_at->toIso8601String(),
            'changed_files' => $commit->changed_files,
            'additions' => $commit->additions,
            'deletions' => $commit->deletions,
            'files_changed' => $commit->diff_summary,
            'patch' => $commit->patch,
        ];

        return new ToolResult(
            summary: sprintf(
                'Commit %s "%s" — %d files, +%d/-%d',
                $payload['sha'],
                \Illuminate\Support\Str::limit($commit->message, 60),
                count($commit->changed_files),
                $commit->additions,
                $commit->deletions,
            ),
            payload: $payload,
            source: 'github',
            kind: 'diff',
            sourceRef: $payload['sha'],
            sourceUrl: $commit->html_url,
        );
    }
}
