<?php

namespace App\Agent\Tools;

use App\Models\Investigation;

interface AgentTool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema for the tool's arguments. */
    public function parameters(): array;

    /** Read-only tools run freely; write tools are gated behind approval. */
    public function isWrite(): bool;

    public function execute(Investigation $investigation, array $arguments): ToolResult;
}
