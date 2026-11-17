<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\TenantSupplyLinkResource\Pages;

use App\Filament\Platform\Resources\TenantSupplyLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTenantSupplyLinks extends ListRecords
{
    protected static string $resource = TenantSupplyLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
