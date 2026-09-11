<?php

namespace App\Agent;

class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}

    public static function fromArray(array $call): self
    {
        $raw = $call['function']['arguments'] ?? '{}';
        $arguments = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return new self(
            id: (string) ($call['id'] ?? uniqid('call_')),
            name: (string) ($call['function']['name'] ?? ''),
            arguments: is_array($arguments) ? $arguments : [],
        );
    }

    public function toAssistantPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($this->arguments),
            ],
        ];
    }
}
