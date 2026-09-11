<?php

namespace App\Agent\Tools;

use App\Models\Deployment;
use App\Models\Investigation;

class ListDeploymentsTool implements AgentTool
{
    public function name(): string
    {
        return 'list_deployments';
    }

    public function description(): string
    {
        return 'List deployments that shipped during the incident window, with their commit '
            .'message, author, timestamp and changed files.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['window_minutes'],
            'properties' => [
                'window_minutes' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 240,
                    'description' => 'How many minutes back from the incident to search.',
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
        $incident = $investigation->incident;
        $minutes = (int) ($arguments['window_minutes'] ?? 30);
        $from = $incident->window_end->copy()->subMinutes($minutes);

        $deployments = Deployment::with('commit')
            ->whereBetween('deployed_at', [$from, $incident->window_end])
            ->orderByDesc('deployed_at')
            ->get();

        $payload = [
            'window_minutes' => $minutes,
            'count' => $deployments->count(),
            'deployments' => $deployments->map(fn (Deployment $d) => [
                'sha' => substr($d->sha, 0, 7),
                'message' => $d->commit?->message ?? $d->release_name,
                'author' => $d->author,
                'deployed_at' => $d->deployed_at->toIso8601String(),
                'changed_files' => $d->commit?->changed_files ?? [],
            ])->all(),
        ];

        return new ToolResult(
            summary: sprintf('%d deployment(s) in the last %d minutes', $deployments->count(), $minutes),
            payload: $payload,
            source: 'github',
            kind: 'deployment',
            sourceRef: "deployments:{$minutes}m",
        );
    }
}
