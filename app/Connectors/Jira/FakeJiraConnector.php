<?php

namespace App\Connectors\Jira;

use App\Connectors\Contracts\JiraConnector;

class FakeJiraConnector implements JiraConnector
{
    public function createIssue(string $summary, string $description, array $labels = []): array
    {
        $key = (config('connectors.jira.project_key') ?: 'INC').'-'.random_int(100, 999);

        return ['key' => $key, 'url' => "https://jira.example/browse/{$key}", 'fake' => true];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
