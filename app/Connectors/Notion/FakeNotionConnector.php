<?php

namespace App\Connectors\Notion;

use App\Connectors\Contracts\NotionConnector;
use Illuminate\Support\Str;

class FakeNotionConnector implements NotionConnector
{
    public function createPage(string $title, string $markdown): array
    {
        $id = (string) Str::uuid();

        return ['id' => $id, 'url' => 'https://notion.example/'.str_replace('-', '', $id), 'fake' => true];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
