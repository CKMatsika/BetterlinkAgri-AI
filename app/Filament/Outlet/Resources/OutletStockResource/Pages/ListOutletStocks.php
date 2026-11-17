<?php

declare(strict_types=1);

namespace App\Filament\Outlet\Resources\OutletStockResource\Pages;

use App\Actions\Outlets\ParseWhatsAppStockUpdateAction;
use App\Filament\Outlet\Resources\OutletStockResource;
use App\Models\Outlet;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListOutletStocks extends ListRecords
{
    protected static string $resource = OutletStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('quick_text_update')
                ->label('Quick WhatsApp-Style Stock Update')
                ->icon('heroicon-o-Bolt')
                ->form([
                    Forms\Components\TextInput::make('stock_line')
                        ->label('Stock Line (e.g. "Compound D 50kg: 40 bags USD 32")')
                        ->placeholder('Compound D 50kg: 40 bags USD 32')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $outlet = auth()->user()?->outlets()->first() ?? Outlet::query()->first();
                    if ($outlet === null) {
                        return;
                    }

                    $res = app(ParseWhatsAppStockUpdateAction::class)->execute($outlet, (string) $data['stock_line']);
                    if ($res['updated']) {
                        Notification::make()->title($res['message'])->success()->send();
                    } else {
                        Notification::make()->title($res['message'])->danger()->send();
                    }
                }),
        ];
    }
}
