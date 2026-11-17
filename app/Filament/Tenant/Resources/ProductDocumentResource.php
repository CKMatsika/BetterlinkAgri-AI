<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\ProductDocumentResource\Pages;
use App\Jobs\IngestProductDocumentJob;
use App\Models\ProductDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class ProductDocumentResource extends Resource
{
    protected static ?string $model = ProductDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationGroup = 'Catalogue & Knowledge Base';

    protected static ?string $navigationLabel = 'Knowledge Base (RAG PDFs)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Upload Approved Agronomy Document or Product Label (PDF -> Chunks -> pgvector)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('document_type')
                        ->options([
                            'product_label' => 'Registered Product Label',
                            'agronomy_guide' => 'Crop Agronomy Guide',
                            'pest_guide' => 'Pest & Disease Identification Guide',
                            'nutrient_guide' => 'Nutrient Deficiency & Soil Guide',
                            'fertiliser_table' => 'Fertiliser & Pfumvudza Recommendation Table',
                            'livestock_guide' => 'Livestock Health & Veterinary Guide',
                        ])
                        ->required(),
                    Forms\Components\Select::make('product_id')
                        ->relationship('product', 'name')
                        ->label('Linked Product SKU (Optional)')
                        ->searchable(),
                    Forms\Components\Select::make('language')
                        ->options([
                            'en' => 'English',
                            'sn' => 'ChiShona',
                            'nd' => 'isiNdebele',
                        ])
                        ->default('en')
                        ->required(),
                    Forms\Components\FileUpload::make('file_path')
                        ->label('PDF or Text Document')
                        ->disk((string) config('betterlink.media.disk', 'local'))
                        ->directory('tenant-knowledge-docs')
                        ->acceptedFileTypes(['application/pdf', 'text/plain'])
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('document_type')->badge(),
                Tables\Columns\TextColumn::make('language')->badge(),
                Tables\Columns\TextColumn::make('ingestion_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('chunks_count')->label('Embedded Chunks'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('reingest')
                    ->label('Chunk & Embed Now')
                    ->icon('heroicon-o-cpu-chip')
                    ->action(function (ProductDocument $record): void {
                        IngestProductDocumentJob::dispatch($record->id);
                        Notification::make()
                            ->title('RAG ingestion job dispatched to queue.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductDocuments::route('/'),
        ];
    }
}
