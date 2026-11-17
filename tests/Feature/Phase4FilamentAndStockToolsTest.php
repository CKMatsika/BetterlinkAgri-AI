<?php

declare(strict_types=1);

use App\Actions\Outlets\ParseWhatsAppStockUpdateAction;
use App\Actions\Outlets\RespondToReservationAction;
use App\Actions\Rag\ChunkAndEmbedDocumentAction;
use App\Enums\ConversationState;
use App\Enums\CurrencyCode;
use App\Enums\DiagnosisCategory;
use App\Enums\StockQuantityBand;
use App\Filament\Tenant\Components\ConnectedFarmerProfilePanel;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\SalesAttribution;
use App\Models\Tenant;
use App\Services\Outlets\ReservationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('parses WhatsApp-style stock update lines and updates outlet_stock with fresh timestamps', function (): void {
    $outlet = Outlet::withoutGlobalScopes()->where('name', 'LIKE', '%Glendale%')->firstOrFail();
    $parser = app(ParseWhatsAppStockUpdateAction::class);

    $res1 = $parser->execute($outlet, 'Compound D 50kg: 40 bags USD 34');
    expect($res1['updated'])->toBeTrue()
        ->and($res1['quantity_band'])->toBe(StockQuantityBand::IN_STOCK->value)
        ->and($res1['exact_quantity'])->toBe(40);

    $res2 = $parser->execute($outlet, 'BeltGuard 100g: 0 bags');
    expect($res2['updated'])->toBeTrue()
        ->and($res2['quantity_band'])->toBe(StockQuantityBand::OUT_OF_STOCK->value)
        ->and($res2['exact_quantity'])->toBe(0);
});

it('confirms reservations, marks them sold, closes the lead, and records sales_attribution', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $outlet = Outlet::withoutGlobalScopes()->where('name', 'LIKE', '%Glendale%')->firstOrFail();
    $product = Product::withoutGlobalScopes()->where('sku', '=', 'FERT-COMPOUND-D-50KG')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263776111222', 'Simba Marufu');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::RESERVATION_PENDING,
    ]);

    $reservation = app(ReservationService::class)->createReservationAndLead(
        tenant: $tenant,
        conversation: $conversation,
        farmer: $farmer,
        outlet: $outlet,
        basketItems: [
            [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'pack_size' => '50kg',
                'quantity' => 5,
                'price_usd' => 32.00,
                'price_zig' => 816.00,
            ],
        ]
    );

    $action = app(RespondToReservationAction::class);
    $action->confirmHold($reservation);
    expect($reservation->fresh()->status)->toBe('confirmed');

    $action->markFulfilledAndRecordAttribution($reservation, CurrencyCode::USD);

    expect($reservation->fresh()->status)->toBe('picked_up')
        ->and($reservation->lead->fresh()->status)->toBe('won')
        ->and(SalesAttribution::withoutGlobalScopes()->count())->toBe(1);

    $attr = SalesAttribution::withoutGlobalScopes()->firstOrFail();
    expect((float) $attr->total_amount)->toBe(160.00)
        ->and($attr->is_first_ai_engaged_sale)->toBeTrue();
});

it('builds the live Connected Farmer Profile Panel with context chip and approved portfolio', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263776333444', 'Vimbai Sibanda');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::ADVISORY_DELIVERED,
        'context_chip' => 'Maize / V6 vegetative / pest',
    ]);

    Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'growth_stage' => 'V6 vegetative',
        'field_size_ha' => 1.50,
        'final_diagnosis_name' => 'Fall Armyworm',
        'final_diagnosis_code' => 'fall_armyworm',
        'confidence' => 0.91,
    ]);

    $panelData = app(ConnectedFarmerProfilePanel::class)->buildForConversation($conversation);

    expect($panelData['full_name'])->toBe('Vimbai Sibanda')
        ->and($panelData['crop_and_variety'])->toBe('Maize')
        ->and($panelData['recent_challenge'])->toBe('Fall Armyworm')
        ->and($panelData['recent_confidence_pct'])->toBe(91)
        ->and($panelData['approved_portfolio_for_context'])->not->toBeEmpty()
        ->and($panelData['all_approved_options'])->not->toBeEmpty();
});

it('chunks and embeds uploaded product documents into knowledge_chunks', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    Storage::disk('local')->put('tenant-knowledge-docs/test-guide.txt', str_repeat('Fall armyworm scouting and whorl application guide for Zimbabwe maize. ', 25));

    $doc = ProductDocument::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'document_type' => 'pest_guide',
        'title' => 'Test FAW Scouting Guide',
        'language' => 'en',
        'file_path' => 'tenant-knowledge-docs/test-guide.txt',
        'file_hash' => hash('sha256', 'test-guide'),
        'ingestion_status' => 'pending',
    ]);

    $chunksCount = app(ChunkAndEmbedDocumentAction::class)->execute($doc, 'maize');

    expect($chunksCount)->toBeGreaterThanOrEqual(2)
        ->and($doc->fresh()->ingestion_status)->toBe('completed');
});
