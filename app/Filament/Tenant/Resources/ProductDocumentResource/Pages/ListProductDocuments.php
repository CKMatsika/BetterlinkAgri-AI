<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ProductDocumentResource\Pages;

use App\Filament\Tenant\Resources\ProductDocumentResource;
use App\Jobs\IngestProductDocumentJob;
use App\Models\ProductDocument;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListProductDocuments extends ListRecords
{
    protected static string $resource = ProductDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['file_hash'] = hash('sha256', (string) ($data['file_path'] ?? uniqid()));
                    $data['ingestion_status'] = 'pending';

                    return $data;
                })
                ->after(function (ProductDocument $record): void {
                    IngestProductDocumentJob::dispatch($record->id);
                }),
        ];
    }
}
