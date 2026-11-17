<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProviderContract;
use App\Contracts\AiToolContract;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Services\Ai\Tools\CalculateApplicationRateTool;
use App\Services\Ai\Tools\CheckOutletStockTool;
use App\Services\Ai\Tools\CreateLeadTool;
use App\Services\Ai\Tools\CreateReservationTool;
use App\Services\Ai\Tools\FindNearestOutletsTool;
use App\Services\Ai\Tools\GetWeatherTool;
use App\Services\Ai\Tools\HandoffToHumanTool;
use App\Services\Ai\Tools\IdentifyFromImageTool;
use App\Services\Ai\Tools\LookupProductsTool;
use App\Services\Ai\Tools\RequestFarmerLocationTool;
use App\Services\Ai\Tools\ScheduleMeetingTool;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use App\Services\Ai\Tools\UpdateFarmerProfileTool;
use InvalidArgumentException;

/**
 * Central AI Agent Service behind swappable AiProviderContract.
 * Registers all 13 deterministic tools and logs every tool execution to audit_logs.
 */
final class AiAgentService
{
    /** @var array<string, AiToolContract> */
    private array $tools = [];

    public function __construct(
        private readonly AiProviderContract $aiProvider,
        IdentifyFromImageTool $identifyFromImageTool,
        SearchKnowledgeTool $searchKnowledgeTool,
        LookupProductsTool $lookupProductsTool,
        CalculateApplicationRateTool $calculateApplicationRateTool,
        GetWeatherTool $getWeatherTool,
        UpdateFarmerProfileTool $updateFarmerProfileTool,
        FindNearestOutletsTool $findNearestOutletsTool,
        CheckOutletStockTool $checkOutletStockTool,
        CreateReservationTool $createReservationTool,
        RequestFarmerLocationTool $requestFarmerLocationTool,
        CreateLeadTool $createLeadTool,
        HandoffToHumanTool $handoffToHumanTool,
        ScheduleMeetingTool $scheduleMeetingTool,
    ) {
        $all = [
            $identifyFromImageTool,
            $searchKnowledgeTool,
            $lookupProductsTool,
            $calculateApplicationRateTool,
            $getWeatherTool,
            $updateFarmerProfileTool,
            $findNearestOutletsTool,
            $checkOutletStockTool,
            $createReservationTool,
            $requestFarmerLocationTool,
            $createLeadTool,
            $handoffToHumanTool,
            $scheduleMeetingTool,
        ];

        foreach ($all as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Return all 13 registered tool names.
     *
     * @return list<string>
     */
    public function registeredToolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Return Anthropic-compatible tool definitions array.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function toolDefinitionsForProvider(): array
    {
        $defs = [];
        foreach ($this->tools as $tool) {
            $defs[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema(),
            ];
        }

        return $defs;
    }

    /**
     * Execute a named deterministic tool and record an AuditLog entry.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function executeTool(Conversation $conversation, string $toolName, array $input): array
    {
        if (!isset($this->tools[$toolName])) {
            throw new InvalidArgumentException("Unknown AI tool: {$toolName}");
        }

        $tool = $this->tools[$toolName];
        $output = $tool->execute($conversation, $input);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'event_type' => 'tool_execution',
            'tool_calls_json' => [
                [
                    'tool_name' => $toolName,
                    'input' => $input,
                    'output_summary' => array_keys($output),
                ],
            ],
            'created_at' => now(),
        ]);

        return $output;
    }

    public function provider(): AiProviderContract
    {
        return $this->aiProvider;
    }
}
