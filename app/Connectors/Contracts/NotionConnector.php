<?php

namespace App\Connectors\Contracts;

interface NotionConnector
{
    /** @return array{id:string,url:string} */
    public function createPage(string $title, string $markdown): array;

    public function isConfigured(): bool;
}
