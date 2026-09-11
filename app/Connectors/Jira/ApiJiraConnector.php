<?php

namespace App\Connectors\Jira;

use App\Connectors\Contracts\JiraConnector;
use Illuminate\Support\Facades\Http;

class ApiJiraConnector implements JiraConnector
{
    public function createIssue(string $summary, string $description, array $labels = []): array
    {
        $baseUrl = rtrim((string) config('connectors.jira.base_url'), '/');

        $response = Http::withBasicAuth(
            (string) config('connectors.jira.email'),
            (string) config('connectors.jira.api_token'),
        )
            ->asJson()
            ->timeout(30)
            ->post("{$baseUrl}/rest/api/3/issue", [
                'fields' => [
                    'project' => ['key' => config('connectors.jira.project_key')],
                    'issuetype' => ['name' => config('connectors.jira.issue_type')],
                    'summary' => mb_substr($summary, 0, 250),
                    'description' => $this->toAtlassianDocument($description),
                    'labels' => $labels,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Jira issue creation failed: '.\Illuminate\Support\Str::limit($response->body(), 300),
            );
        }

        $key = $response->json('key');

        return ['key' => $key, 'url' => "{$baseUrl}/browse/{$key}"];
    }

    public function isConfigured(): bool
    {
        return filled(config('connectors.jira.base_url'))
            && filled(config('connectors.jira.api_token'))
            && filled(config('connectors.jira.project_key'));
    }

    /**
     * Jira Cloud v3 takes Atlassian Document Format, not markdown. Paragraphs and
     * headings cover what the report emits; anything richer degrades to text
     * rather than failing the ticket.
     */
    private function toAtlassianDocument(string $markdown): array
    {
        $content = [];

        foreach (preg_split('/\n{2,}/', trim($markdown)) as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/s', $block, $m)) {
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => min(6, strlen($m[1]))],
                    'content' => [['type' => 'text', 'text' => trim($m[2])]],
                ];

                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => mb_substr($block, 0, 3000)]],
            ];
        }

        return ['type' => 'doc', 'version' => 1, 'content' => $content ?: [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '(no content)']]],
        ]];
    }
}
