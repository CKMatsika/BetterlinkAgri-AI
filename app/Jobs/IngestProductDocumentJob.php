<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Rag\ChunkAndEmbedDocumentAction;
use App\Models\ProductDocument;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IngestProductDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $productDocumentId,
        public readonly ?string $cropCode = null,
    ) {
        $this->onQueue('rag-ingestion');
    }

    public function handle(
        TenantContext $tenantContext,
        ChunkAndEmbedDocumentAction $chunkAction
    ): void {
        $document = ProductDocument::withoutGlobalScopes()->findOrFail($this->productDocumentId);

        $tenantContext->runForTenant($document->tenant_id, function () use ($document, $chunkAction): void {
            $chunkAction->execute($document, $this->cropCode);
        });
    }
}
