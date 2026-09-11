<?php

namespace App\Agent;

class ChatResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $finishReason,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $payload): self
    {
        $choice = $payload['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        return new self(
            content: (string) ($message['content'] ?? ''),
            toolCalls: array_map(
                fn (array $call) => ToolCall::fromArray($call),
                $message['tool_calls'] ?? [],
            ),
            promptTokens: (int) ($payload['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($payload['usage']['completion_tokens'] ?? 0),
            finishReason: (string) ($choice['finish_reason'] ?? 'stop'),
            raw: $payload,
        );
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * Decode a response produced under a json_schema response_format.
     *
     * llama.cpp enforces the grammar, so this should always parse; a malformed
     * body means the model or server disregarded the schema and the caller
     * needs to know rather than silently receive an empty array.
     */
    public function json(): array
    {
        $decoded = json_decode($this->content, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException(
                'Model did not return valid JSON under a strict schema: '
                .\Illuminate\Support\Str::limit($this->content, 300),
            );
        }

        return $decoded;
    }
}
