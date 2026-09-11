<?php

namespace App\Connectors\Notion;

use App\Connectors\Contracts\NotionConnector;
use Illuminate\Support\Facades\Http;

class ApiNotionConnector implements NotionConnector
{
    public function createPage(string $title, string $markdown): array
    {
        $response = Http::withToken((string) config('connectors.notion.token'))
            ->withHeaders(['Notion-Version' => config('connectors.notion.version')])
            ->asJson()
            ->timeout(30)
            ->post('https://api.notion.com/v1/pages', [
                'parent' => ['page_id' => config('connectors.notion.parent_page_id')],
                'properties' => [
                    'title' => [
                        'title' => [['text' => ['content' => mb_substr($title, 0, 200)]]],
                    ],
                ],
                'children' => $this->toBlocks($markdown),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Notion page creation failed: '.\Illuminate\Support\Str::limit($response->body(), 300),
            );
        }

        return ['id' => $response->json('id'), 'url' => $response->json('url')];
    }

    public function isConfigured(): bool
    {
        return filled(config('connectors.notion.token'))
            && filled(config('connectors.notion.parent_page_id'));
    }

    /** Notion caps children at 100 blocks per request, so the tail is trimmed. */
    private function toBlocks(string $markdown): array
    {
        $blocks = [];

        foreach (explode("\n", $markdown) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]);
                $blocks[] = $this->block("heading_{$level}", $m[2]);

                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
                $blocks[] = $this->block('bulleted_list_item', $m[1]);

                continue;
            }

            $blocks[] = $this->block('paragraph', $line);
        }

        return array_slice($blocks, 0, 100);
    }

    private function block(string $type, string $text): array
    {
        return [
            'object' => 'block',
            'type' => $type,
            $type => [
                'rich_text' => [[
                    'type' => 'text',
                    'text' => ['content' => mb_substr($this->stripMarkdown($text), 0, 2000)],
                ]],
            ],
        ];
    }

    private function stripMarkdown(string $text): string
    {
        return trim(preg_replace('/[*_`|]/', '', $text));
    }
}
