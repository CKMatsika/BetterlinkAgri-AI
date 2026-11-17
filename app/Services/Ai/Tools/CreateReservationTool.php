<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Conversation;
use App\Models\Outlet;
use App\Services\Outlets\ReservationService;

final class CreateReservationTool implements AiToolContract
{
    public function __construct(
        private readonly ReservationService $reservationService
    ) {
    }

    public function name(): string
    {
        return 'create_reservation';
    }

    public function description(): string
    {
        return 'Create an outlet stock reservation ("Hold this for me") and linked lead with a 30-minute outlet SLA timer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outlet_id' => ['type' => 'string'],
                'basket_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'string'],
                            'sku' => ['type' => 'string'],
                            'product_name' => ['type' => 'string'],
                            'pack_size' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer'],
                            'price_usd' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'required' => ['outlet_id', 'basket_items'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $outlet = Outlet::withoutGlobalScopes()->findOrFail((string) $input['outlet_id']);
        $farmer = $conversation->farmer;
        if ($farmer === null) {
            return ['error' => 'Farmer not found'];
        }

        $diagnosis = $conversation->diagnoses()->latest()->first();

        $reservation = $this->reservationService->createReservationAndLead(
            tenant: $conversation->tenant,
            conversation: $conversation,
            farmer: $farmer,
            outlet: $outlet,
            basketItems: (array) $input['basket_items'],
            diagnosis: $diagnosis
        );

        return [
            'reservation_id' => $reservation->id,
            'lead_id' => $reservation->lead_id,
            'status' => $reservation->status,
            'sla_due_at' => $reservation->sla_due_at?->toIso8601String(),
        ];
    }
}
