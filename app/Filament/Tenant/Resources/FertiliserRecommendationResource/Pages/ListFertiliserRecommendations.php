<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FertiliserRecommendationResource\Pages;

use App\Filament\Tenant\Resources\FertiliserRecommendationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListFertiliserRecommendations extends ListRecords
{
    protected static string $resource = FertiliserRecommendationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
