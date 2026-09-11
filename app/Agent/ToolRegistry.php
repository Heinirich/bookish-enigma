<?php

namespace App\Agent;

use App\Agent\Tools\AgentTool;

class ToolRegistry
{
    /** @var array<string,AgentTool> */
    private array $tools = [];

    /** @param array<int,AgentTool> $tools */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AgentTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<int,AgentTool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** @return array<int,AgentTool> */
    public function readOnly(): array
    {
        return array_values(array_filter($this->all(), fn (AgentTool $t) => ! $t->isWrite()));
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Tool definitions in OpenAI function-calling form, which Jan accepts natively.
     *
     * @param  array<int,AgentTool>|null  $tools
     */
    public function toOpenAiSchema(?array $tools = null): array
    {
        return array_map(fn (AgentTool $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ], $tools ?? $this->readOnly());
    }
}
