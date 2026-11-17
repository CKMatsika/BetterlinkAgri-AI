<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BenchmarkResource\Pages;
use App\Models\Benchmark;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class BenchmarkResource extends Resource
{
    protected static ?string $model = Benchmark::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Farm Planning & Benchmarks';

    protected static ?string $navigationLabel = 'Agronomic Benchmarks';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('enterprise_code')
                ->options([
                    'broilers' => 'Broilers',
                    'maize' => 'Maize',
                    'wheat' => 'Winter Wheat',
                    'tobacco' => 'Tobacco',
                    'dairy' => 'Dairy',
                ])
                ->required(),
            Forms\Components\Select::make('natural_region')
                ->options([
                    'ALL' => 'All Natural Regions',
                    'I' => 'NR I',
                    'IIa' => 'NR IIa',
                    'IIb' => 'NR IIb',
                    'III' => 'NR III',
                    'IV' => 'NR IV',
                    'V' => 'NR V',
                ])
                ->default('ALL')
                ->required(),
            Forms\Components\TextInput::make('metric_key')->required(),
            Forms\Components\Select::make('value_type')
                ->options([
                    'integer_minor' => 'Money (Integer Minor Units / Cents)',
                    'decimal_2' => 'Scaled * 100 (e.g. 5.00% = 500)',
                    'integer' => 'Whole Integer',
                ])
                ->default('integer_minor')
                ->required(),
            Forms\Components\TextInput::make('value_scaled')->numeric()->required(),
            Forms\Components\TextInput::make('range_low_scaled')->numeric(),
            Forms\Components\TextInput::make('range_high_scaled')->numeric(),
            Forms\Components\TextInput::make('unit')->required(),
            Forms\Components\Select::make('currency')
                ->options(['USD' => 'USD', 'ZIG' => 'ZiG']),
            Forms\Components\DatePicker::make('effective_date')->required(),
            Forms\Components\Select::make('status')
                ->options([
                    'unverified' => 'Unverified (Demo / Draft)',
                    'verified' => 'Verified by Agronomist',
                ])
                ->default('unverified')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('enterprise_code')->badge()->sortable(),
                Tables\Columns\TextColumn::make('natural_region')->badge(),
                Tables\Columns\TextColumn::make('metric_key')->searchable(),
                Tables\Columns\TextColumn::make('value_scaled')->label('Scaled / Minor Value'),
                Tables\Columns\TextColumn::make('unit'),
                Tables\Columns\TextColumn::make('source.title')->label('Source'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'verified' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('effective_date')->date(),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify Benchmark')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Benchmark $record): bool => $record->status !== 'verified')
                    ->action(function (Benchmark $record): void {
                        $record->update([
                            'status' => 'verified',
                            'reviewed_by' => auth()->id(),
                        ]);
                        Notification::make()->title('Benchmark marked verified.')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBenchmarks::route('/'),
        ];
    }
}
