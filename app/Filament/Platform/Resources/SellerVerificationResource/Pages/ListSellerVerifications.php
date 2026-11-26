<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\SellerVerificationResource\Pages;

use App\Filament\Platform\Resources\SellerVerificationResource;
use Filament\Resources\Pages\ListRecords;

final class ListSellerVerifications extends ListRecords
{
    protected static string $resource = SellerVerificationResource::class;
}
