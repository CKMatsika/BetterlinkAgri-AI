<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\FarmerPrivacyController;
use App\Http\Controllers\Api\V1\OutletPortalApiController;
use App\Http\Controllers\Api\V1\SmsUssdWebhookController;
use App\Http\Controllers\Api\V1\WebChatController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // 1. WhatsApp Cloud API Webhooks (Signature verification + Redis Idempotency)
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->middleware(['whatsapp.signature', 'whatsapp.idempotency'])
        ->name('webhooks.whatsapp.handle');

    // 2. SMS / USSD Fallback Gateway Webhook
    Route::post('/webhooks/sms-ussd', [SmsUssdWebhookController::class, 'handle'])
        ->name('webhooks.sms_ussd.handle');

    // 3. Web Chat Widget & Mobile App Endpoints
    Route::post('/web-chat/messages', [WebChatController::class, 'sendMessage'])
        ->name('web_chat.send_message');

    Route::post('/web-chat/feedback', [WebChatController::class, 'submitFeedback'])
        ->name('web_chat.submit_feedback');

    // 4. Outlet Portal & ERP Stock / Reservation Endpoints
    Route::post('/outlets/{outletId}/stock-text', [OutletPortalApiController::class, 'updateStockViaText'])
        ->name('outlets.stock_text');

    Route::post('/reservations/{reservationId}/respond', [OutletPortalApiController::class, 'respondToReservation'])
        ->name('reservations.respond');

    // 5. Tenant-Scoped Protected Endpoints (CDPA Data Export & Right to Erasure)
    Route::middleware(['auth:sanctum', 'tenant.resolve'])->group(function (): void {
        Route::post('/privacy/farmer/export', [FarmerPrivacyController::class, 'export'])
            ->name('privacy.farmer.export');

        Route::delete('/privacy/farmer/erase', [FarmerPrivacyController::class, 'erase'])
            ->name('privacy.farmer.erase');
    });
});
