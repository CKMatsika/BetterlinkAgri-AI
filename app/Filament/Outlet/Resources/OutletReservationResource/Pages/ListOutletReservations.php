<?php

declare(strict_types=1);

namespace App\Filament\Outlet\Resources\OutletReservationResource\Pages;

use App\Filament\Outlet\Resources\OutletReservationResource;
use Filament\Resources\Pages\ListRecords;

final class ListOutletReservations extends ListRecords
{
    protected static string $resource = OutletReservationResource::class;
}
