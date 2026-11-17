<?php

declare(strict_types=1);

namespace App\Actions\Rag;

use App\Contracts\EmbeddingProviderContract;
use App\Models\KnowledgeChunk;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Parses an uploaded ProductDocument (PDF or text), splits it into semantic overlapping chunks,
 * generates vector embeddings via EmbeddingProviderContract, and stores KnowledgeChunk rows.
 */
final class ChunkAndEmbedDocumentAction
{
    public function __construct(
        private readonly EmbeddingProviderContract $embeddingProvider
    ) {
    }

    public function execute(ProductDocument $document, ?string $cropCode = null): int
    {
        $document->update(['ingestion_status' => 'processing']);

        try {
            $disk = (string) config('betterlink.media.disk', 'local');
            $rawContent = '';

            if (Storage::disk($disk)->exists($document->file_path)) {
                $bytes = (string) Storage::disk($disk)->get($document->file_path);

                if (str_ends_with(strtolower($document->file_path), '.pdf') && class_exists(PdfParser::class)) {
                    try {
                        $parser = new PdfParser();
                        $pdf = $parser->parseContent($bytes);
                        $rawContent = $pdf->getText();
                    } catch (Throwable) {
                        $rawContent = $bytes;
                    }
                } else {
                    $rawContent = $bytes;
                }
            }

            if (trim($rawContent) === '') {
                $rawContent = $document->title;
            }

            $chunks = $this->splitTextIntoSemanticChunks($rawContent, 800, 120);

            KnowledgeChunk::withoutGlobalScopes()
                ->where('product_document_id', '=', $document->id)
                ->delete();

            foreach ($chunks as $idx => $chunkText) {
                $vector = $this->embeddingProvider->embedText($chunkText);

                $chunkModel = KnowledgeChunk::withoutGlobalScopes()->create([
                    'tenant_id' => $document->tenant_id,
                    'product_document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'crop_code' => $cropCode,
                    'language' => $document->language,
                    'title' => "{$document->title} (Part " . ($idx + 1) . ')',
                    'chunk_index' => $idx + 1,
                    'page_number' => $idx + 1,
                    'content' => $chunkText,
                    'metadata_json' => [
                        'source_document_id' => $document->id,
                        'document_title' => $document->title,
                    ],
                    'is_active' => true,
                ]);

                if (DB::getDriverName() === 'pgsql') {
                    $vectorLiteral = '[' . implode(',', $vector) . ']';
                    DB::statement(
                        "UPDATE knowledge_chunks SET embedding = '{$vectorLiteral}'::vector WHERE id = ?",
                        [$chunkModel->id]
                    );
                } else {
                    $chunkModel->update(['embedding' => $vector]);
                }
            }

            $count = count($chunks);
            $document->update([
                'ingestion_status' => 'completed',
                'chunks_count' => $count,
            ]);

            return $count;
        } catch (Throwable $e) {
            $document->update(['ingestion_status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Split long document text into overlapping chunks of roughly $targetLength characters.
     *
     * @return list<string>
     */
    private function splitTextIntoSemanticChunks(string $text, int $targetLength = 800, int $overlap = 120): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        if (strlen($normalized) <= $targetLength) {
            return [$normalized];
        }

        $chunks = [];
        $offset = 0;
        $totalLen = strlen($normalized);

        while ($offset < $totalLen) {
            $slice = substr($normalized, $offset, $targetLength);
            if ($offset + $targetLength < $totalLen) {
                $lastPeriod = strrpos($slice, '. ');
                if ($lastPeriod !== false && $lastPeriod > ($targetLength / 2)) {
                    $slice = substr($slice, 0, $lastPeriod + 1);
                }
            }

            $chunks[] = trim($slice);
            $step = max(1, strlen($slice) - $overlap);
            $offset += $step;
        }

        return $chunks;
    }
}
