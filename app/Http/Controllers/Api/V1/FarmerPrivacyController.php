<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Consent\ExportOrEraseFarmerDataAction;
use App\Models\Farmer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FarmerPrivacyController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ExportOrEraseFarmerDataAction $privacyAction
    ) {
    }

    /**
     * Export portable JSON of a farmer's data under the Zimbabwe Cyber and Data Protection Act.
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
        ]);

        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            abort(Response::HTTP_BAD_REQUEST, 'Active tenant context is required.');
        }

        $farmer = Farmer::findByPhone((string) $validated['phone']);
        if ($farmer === null) {
            abort(Response::HTTP_NOT_FOUND, 'Farmer record not found.');
        }

        $payload = $this->privacyAction->exportForTenant($tenantId, $farmer);

        return response()->json($payload, Response::HTTP_OK);
    }

    /**
     * Right to erasure / anonymization of farmer PII under the Zimbabwe Cyber and Data Protection Act.
     */
    public function erase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
        ]);

        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            abort(Response::HTTP_BAD_REQUEST, 'Active tenant context is required.');
        }

        $farmer = Farmer::findByPhone((string) $validated['phone']);
        if ($farmer === null) {
            abort(Response::HTTP_NOT_FOUND, 'Farmer record not found.');
        }

        $this->privacyAction->eraseAndAnonymize($tenantId, $farmer);

        return response()->json([
            'status' => 'erased_and_anonymized',
            'farmer_id' => $farmer->id,
        ], Response::HTTP_OK);
    }
}
