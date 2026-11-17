<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\OutbreakSignal;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

final class WardOutbreakAlertMapWidget extends BaseWidget
{
    protected static ?string $heading = 'Regional & Ward Outbreak Alerts (Rolling 7-Day Cluster Detector)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(OutbreakSignal::query()->with(['ward', 'district', 'crop'])->latest('detected_at'))
            ->columns([
                Tables\Columns\TextColumn::make('district.name')->label('District'),
                Tables\Columns\TextColumn::make('ward.name')->label('Ward'),
                Tables\Columns\TextColumn::make('crop.name_en')->label('Crop'),
                Tables\Columns\TextColumn::make('target_code')->label('Pest / Disease')->badge(),
                Tables\Columns\TextColumn::make('cases_count_7d')->label('7d Cases')->sortable(),
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'outbreak' => 'danger',
                        'warning' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('detected_at')->since(),
            ]);
    }
}
