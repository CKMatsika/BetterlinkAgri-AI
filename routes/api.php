<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\FarmPlannerController;
use App\Http\Controllers\Api\V1\FarmerPrivacyController;
use App\Http\Controllers\Api\V1\MarketplaceIntelligenceController;
use App\Http\Controllers\Api\V1\MarketplaceRequestController;
use App\Http\Controllers\Api\V1\MarketplaceTrustController;
use App\Http\Controllers\Api\V1\OutletPortalApiController;
use App\Http\Controllers\Api\V1\PlannerAuthController;
use App\Http\Controllers\Api\V1\SmsUssdWebhookController;
use App\Http\Controllers\Api\V1\WebChatController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Support\ProductionDatabaseGuard;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', function () {
        $status = ProductionDatabaseGuard::status();

        return response()->json($status, $status['ok'] ? 200 : 503);
    })->name('health');
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

    // 4b. Web Farmer Planner Portal Endpoints (P1b + Phone OTP Auth + IDOR & Per-Farmer Rate Limiting)
    Route::prefix('planner')->name('planner.')->group(function (): void {
        Route::get('/enterprises', [FarmPlannerController::class, 'enterprises'])->name('enterprises');

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('/request-otp', [PlannerAuthController::class, 'requestOtp'])
                ->middleware('throttle:planner-otp')
                ->name('request_otp');
            Route::post('/verify-otp', [PlannerAuthController::class, 'verifyOtp'])
                ->middleware('throttle:planner-otp')
                ->name('verify_otp');
            Route::post('/logout', [PlannerAuthController::class, 'logout'])
                ->name('logout');
        });

        Route::middleware(['planner.auth', 'throttle:planner-api'])->group(function (): void {
            Route::get('/plans', [FarmPlannerController::class, 'index'])->name('plans.index');
            Route::post('/plans', [FarmPlannerController::class, 'store'])->name('plans.store');
            Route::get('/plans/{plan}', [FarmPlannerController::class, 'show'])->name('plans.show');
            Route::post('/plans/{plan}/answer', [FarmPlannerController::class, 'answer'])->name('plans.answer');
            Route::post('/plans/{plan}/what-if', [FarmPlannerController::class, 'whatIf'])->name('plans.what_if');
            Route::post('/plans/{plan}/accept', [FarmPlannerController::class, 'accept'])->name('plans.accept');
            Route::get('/plans/{plan}/pdf', [FarmPlannerController::class, 'pdf'])->name('plans.pdf');
        });
    });

    // 4c. Marketplace Core Loop & Outlet Portal Live Alerts Endpoints (M1b + M1c)
    Route::prefix('marketplace')->name('marketplace.')->group(function (): void {
        Route::middleware(['planner.auth', 'throttle:planner-api'])->group(function (): void {
            Route::get('/requests', [MarketplaceRequestController::class, 'index'])->name('requests.index');
            Route::post('/requests', [MarketplaceRequestController::class, 'store'])->name('requests.store');
            Route::get('/requests/{marketplaceRequestId}', [MarketplaceRequestController::class, 'show'])->name('requests.show');
            Route::get('/requests/{marketplaceRequestId}/counters', [MarketplaceRequestController::class, 'counters'])->name('requests.counters');
            Route::get('/requests/{marketplaceRequestId}/substitutes', [MarketplaceRequestController::class, 'substitutes'])->name('requests.substitutes');
            Route::post('/requests/{marketplaceRequestId}/substitutes/confirm', [MarketplaceRequestController::class, 'confirmSubstitute'])->name('requests.substitutes.confirm');
            Route::post('/requests/{marketplaceRequestId}/offers/{offerId}/accept', [MarketplaceRequestController::class, 'acceptOffer'])->name('requests.accept_offer');
            Route::post('/plan-requirements/{requirementId}/activate', [MarketplaceRequestController::class, 'activatePlanRequirement'])->name('plan_requirements.activate');
            Route::post('/orders/{orderId}/rate', [MarketplaceTrustController::class, 'farmerRate'])->name('orders.farmer_rate');
            Route::post('/orders/{orderId}/dispute', [MarketplaceTrustController::class, 'farmerDispute'])->name('orders.farmer_dispute');
        });

        Route::middleware(['auth:sanctum'])->group(function (): void {
            Route::get('/outlet/alerts', [MarketplaceRequestController::class, 'outletAlerts'])->name('outlet.alerts');
            Route::post('/recipients/{recipientId}/opened', [MarketplaceRequestController::class, 'markOpened'])->name('recipients.opened');
            Route::post('/recipients/{recipientId}/respond', [MarketplaceRequestController::class, 'respond'])->name('recipients.respond');
            Route::post('/orders/{orderId}/complete', [MarketplaceIntelligenceController::class, 'completeOrder'])->name('orders.complete');
            Route::post('/orders/{orderId}/rate-farmer', [MarketplaceTrustController::class, 'retailerRate'])->name('orders.retailer_rate');
            Route::post('/orders/{orderId}/dispute/retailer', [MarketplaceTrustController::class, 'retailerDispute'])->name('orders.retailer_dispute');
            Route::post('/orders/{orderId}/dispute/resolve', [MarketplaceTrustController::class, 'resolveDispute'])->name('orders.dispute_resolve');
            Route::post('/seller-verifications', [MarketplaceTrustController::class, 'submitVerification'])->name('seller_verifications.submit');
            Route::post('/seller-verifications/{verificationId}/review', [MarketplaceTrustController::class, 'reviewVerification'])->name('seller_verifications.review');
            Route::get('/intelligence/demand', [MarketplaceIntelligenceController::class, 'demand'])->name('intelligence.demand');
            Route::get('/intelligence/stock-signals', [MarketplaceIntelligenceController::class, 'stockSignals'])->name('intelligence.stock_signals');
            Route::get('/intelligence/upstream-requests', [MarketplaceIntelligenceController::class, 'upstreamRequests'])->name('intelligence.upstream_requests');
        });
    });

    // 5. Tenant-Scoped Protected Endpoints (CDPA Data Export & Right to Erasure)
    Route::middleware(['auth:sanctum', 'tenant.resolve'])->group(function (): void {
        Route::post('/privacy/farmer/export', [FarmerPrivacyController::class, 'export'])
            ->name('privacy.farmer.export');

        Route::delete('/privacy/farmer/erase', [FarmerPrivacyController::class, 'erase'])
            ->name('privacy.farmer.erase');
    });
});
