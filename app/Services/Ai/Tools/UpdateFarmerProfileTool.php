<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\DTOs\RichMessageCardData;
use App\Enums\FarmingSector;
use App\Enums\LanguageCode;
use App\Enums\RichCardType;
use App\Models\Conversation;
use App\Models\Farm;

final class UpdateFarmerProfileTool implements AiToolContract
{
    public function name(): string
    {
        return 'update_farmer_profile';
    }

    public function description(): string
    {
        return 'Progressive profiling tool: update farmer/farm attributes one field at a time and return a profile_updated rich card.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'full_name' => ['type' => 'string'],
                'preferred_language' => ['type' => 'string', 'enum' => ['en', 'sn', 'nd']],
                'ward_id' => ['type' => 'string'],
                'farming_sector' => ['type' => 'string', 'enum' => ['communal', 'a1', 'a2', 'small_scale_commercial', 'large_scale_commercial']],
                'is_pfumvudza_enrolled' => ['type' => 'boolean'],
                'farm_size_ha' => ['type' => 'number'],
                'crops_grown' => ['type' => 'array', 'items' => ['type' => 'string']],
                'livestock_kept' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $farmer = $conversation->farmer;
        if ($farmer === null) {
            return ['error' => 'Farmer not found on conversation'];
        }

        $updatedFields = [];

        if (!empty($input['full_name'])) {
            $farmer->full_name_encrypted = (string) $input['full_name'];
            $updatedFields['full_name'] = (string) $input['full_name'];
        }
        if (!empty($input['preferred_language'])) {
            $lang = LanguageCode::tryFrom((string) $input['preferred_language']) ?? LanguageCode::ENGLISH;
            $farmer->preferred_language = $lang;
            $conversation->update(['language' => $lang]);
            $updatedFields['preferred_language'] = $lang->label();
        }
        if (!empty($input['ward_id'])) {
            $farmer->ward_id = (string) $input['ward_id'];
            $updatedFields['ward_id'] = (string) $input['ward_id'];
        }
        if (!empty($input['farming_sector'])) {
            $sector = FarmingSector::tryFrom((string) $input['farming_sector']);
            if ($sector !== null) {
                $farmer->farming_sector = $sector;
                $updatedFields['farming_sector'] = $sector->label();
            }
        }
        if (isset($input['is_pfumvudza_enrolled'])) {
            $farmer->is_pfumvudza_enrolled = (bool) $input['is_pfumvudza_enrolled'];
            $updatedFields['is_pfumvudza_enrolled'] = (bool) $input['is_pfumvudza_enrolled'];
        }

        $farmer->save();

        if (isset($input['farm_size_ha']) || isset($input['crops_grown']) || isset($input['livestock_kept'])) {
            $farm = Farm::query()->firstOrCreate(
                ['farmer_id' => $farmer->id],
                ['tenant_id' => $conversation->tenant_id, 'ward_id' => $farmer->ward_id]
            );

            if (isset($input['farm_size_ha'])) {
                $farm->cultivated_area_ha = (float) $input['farm_size_ha'];
                $farm->total_area_ha = (float) $input['farm_size_ha'];
                $updatedFields['farm_size_ha'] = (float) $input['farm_size_ha'];
            }
            if (isset($input['crops_grown'])) {
                $farm->crops_grown_json = (array) $input['crops_grown'];
                $updatedFields['crops_grown'] = (array) $input['crops_grown'];
            }
            if (isset($input['livestock_kept'])) {
                $farm->livestock_kept_json = (array) $input['livestock_kept'];
                $updatedFields['livestock_kept'] = (array) $input['livestock_kept'];
            }
            $farm->save();
        }

        $card = new RichMessageCardData(
            cardType: RichCardType::PROFILE_UPDATED,
            title: 'Profile Updated',
            contextChip: $conversation->context_chip ?? 'Farmer Profile',
            payload: ['saved_fields' => $updatedFields]
        );

        return [
            'status' => 'updated',
            'saved_fields' => $updatedFields,
            'rich_card' => $card->toArray(),
        ];
    }
}
