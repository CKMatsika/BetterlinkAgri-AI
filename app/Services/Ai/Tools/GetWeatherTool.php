<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Contracts\WeatherProviderContract;
use App\Models\Conversation;
use App\Models\Ward;

final class GetWeatherTool implements AiToolContract
{
    public function __construct(
        private readonly WeatherProviderContract $weatherService
    ) {
    }

    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Fetch local ward weather forecast and evaluate safe pesticide spraying windows (wind, rain probability, temperature).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ward_id' => ['type' => 'string', 'description' => 'Optional Ward UUID'],
            ],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $wardId = $input['ward_id'] ?? $conversation->farmer?->ward_id;
        $ward = $wardId !== null ? Ward::query()->find((string) $wardId) : Ward::query()->first();

        if ($ward === null) {
            return ['error' => 'No ward available for weather lookup'];
        }

        return $this->weatherService->getSprayingWindowForWard($ward)->toArray();
    }
}
