<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Services\Diagnosis\VisionDiagnosisService;

final class IdentifyFromImageTool implements AiToolContract
{
    public function __construct(
        private readonly VisionDiagnosisService $visionService
    ) {
    }

    public function name(): string
    {
        return 'identify_from_image';
    }

    public function description(): string
    {
        return 'Perform first-pass multimodal identification of a crop pest, disease, nutrient deficiency, weed, or livestock condition from an attachment.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => ['type' => 'string', 'description' => 'UUID of the image Attachment'],
                'farmer_note' => ['type' => 'string', 'description' => 'Optional farmer caption or note'],
            ],
            'required' => ['attachment_id'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $attachment = Attachment::query()->findOrFail((string) $input['attachment_id']);
        $note = isset($input['farmer_note']) ? (string) $input['farmer_note'] : null;

        $result = $this->visionService->identifyFromAttachment($conversation, $attachment, $note);

        return $result->toArray();
    }
}
