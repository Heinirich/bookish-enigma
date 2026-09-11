<?php

namespace App\Agent;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for Jan's OpenAI-compatible endpoint.
 *
 * Jan fronts both the local llama.cpp model and remote providers on the same
 * API, so swapping the synthesis model is a config change. Two capabilities
 * this project depends on and has verified against the running server:
 * native tool calling, and json_schema response formats enforced by grammar.
 */
class JanClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim(config('agent.jan.base_url'), '/'),
            apiKey: config('agent.jan.api_key') ?: null,
            timeout: (int) config('agent.jan.timeout'),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $options  tools, tool_choice, response_format, temperature, max_tokens
     */
    public function chat(string $model, array $messages, array $options = []): ChatResponse
    {
        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], $options);

        $response = $this->request()->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Jan request failed [{$response->status()}]: ".\Illuminate\Support\Str::limit($response->body(), 400),
            );
        }

        return ChatResponse::fromArray($response->json() ?? []);
    }

    /** @return array<int,string> */
    public function models(): array
    {
        $response = $this->request()->get('/models');

        if ($response->failed()) {
            return [];
        }

        return array_map(
            fn (array $m) => $m['id'],
            $response->json('data') ?? [],
        );
    }

    public function isReachable(): bool
    {
        try {
            return $this->request()->timeout(5)->get('/models')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();

        return $this->apiKey ? $request->withToken($this->apiKey) : $request;
    }
}
