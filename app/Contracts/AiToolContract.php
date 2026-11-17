<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Conversation;

interface AiToolContract
{
    public function name(): string;

    public function description(): string;

    /**
     * Return JSON Schema for the tool input parameters (Anthropic tool_use schema).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute the deterministic backend logic for this tool.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(Conversation $conversation, array $input): array;
}
