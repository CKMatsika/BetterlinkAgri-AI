<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ForwardQuoteResource\Pages;

use App\Filament\Tenant\Resources\ForwardQuoteResource;
use Filament\Resources\Pages\ListRecords;

final class ListForwardQuotes extends ListRecords
{
    protected static string $resource = ForwardQuoteResource::class;
}
