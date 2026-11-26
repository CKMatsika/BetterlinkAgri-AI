<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ServiceBookingResource\Pages;

use App\Filament\Tenant\Resources\ServiceBookingResource;
use Filament\Resources\Pages\ListRecords;

final class ListServiceBookings extends ListRecords
{
    protected static string $resource = ServiceBookingResource::class;
}
