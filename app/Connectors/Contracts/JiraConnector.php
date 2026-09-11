<?php

namespace App\Connectors\Contracts;

interface JiraConnector
{
    /** @return array{key:string,url:string} */
    public function createIssue(string $summary, string $description, array $labels = []): array;

    public function isConfigured(): bool;
}
