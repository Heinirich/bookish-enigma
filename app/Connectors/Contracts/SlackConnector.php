<?php

namespace App\Connectors\Contracts;

interface SlackConnector
{
    /** @return array{id:string,name:string,url:string} */
    public function createChannel(string $name, string $purpose): array;

    /** @return array{ts:string,url:string} */
    public function postMessage(string $channelId, string $text): array;

    public function isConfigured(): bool;
}
