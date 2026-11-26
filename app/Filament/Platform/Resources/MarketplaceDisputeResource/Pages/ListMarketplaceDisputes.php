<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\MarketplaceDisputeResource\Pages;

use App\Filament\Platform\Resources\MarketplaceDisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListMarketplaceDisputes extends ListRecords
{
    protected static string $resource = MarketplaceDisputeResource::class;
}
