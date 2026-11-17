<?php

declare(strict_types=1);

use App\Enums\ConversationState;
use App\Enums\LanguageCode;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Diagnosis\MediaIntakeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SampleImageFactory;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('accepts a sharp daylight photo, strips EXIF, compresses it, and passes the quality gate', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263772555666', 'Kudzai Ndlovu');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::INTAKE_PROCESSING,
    ]);

    $message = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'image',
    ]);

    $sharpBytes = SampleImageFactory::createSharpLeafImage(1400, 1000);
    $service = app(MediaIntakeService::class);

    $report = $service->processAndStoreImage($sharpBytes, $message, $farmer);

    expect($report->passed)->toBeTrue()
        ->and($report->exifStripped)->toBeTrue()
        ->and($report->width)->toBeLessThanOrEqual(1280) // resized down to max 1280px
        ->and($report->blurScore)->toBeGreaterThan(60.0)
        ->and($report->brightnessScore)->toBeBetween(35.0, 235.0);
});

it('rejects blurry, dark, and low-resolution photos and provides localised Shona and Ndebele framing tips', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263772555777', 'Sibusiso Ncube');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::INTAKE_PROCESSING,
    ]);

    $message = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'image',
    ]);

    $service = app(MediaIntakeService::class);

    $blurryReport = $service->processAndStoreImage(SampleImageFactory::createBlurryLeafImage(), $message, $farmer);
    expect($blurryReport->passed)->toBeFalse()
        ->and($blurryReport->failureReason)->toBe('too_blurry');

    $darkReport = $service->processAndStoreImage(SampleImageFactory::createDarkNightImage(), $message, $farmer);
    expect($darkReport->passed)->toBeFalse()
        ->and($darkReport->failureReason)->toBe('too_dark');

    $lowResReport = $service->processAndStoreImage(SampleImageFactory::createTinyLowResImage(), $message, $farmer);
    expect($lowResReport->passed)->toBeFalse()
        ->and($lowResReport->failureReason)->toBe('resolution_too_low');

    $shonaGuidance = $service->buildBetterPhotoGuidance(LanguageCode::SHONA, 'too_blurry');
    $ndebeleGuidance = $service->buildBetterPhotoGuidance(LanguageCode::NDEBELE, 'too_dark');

    expect($shonaGuidance)->toContain('Ndapota tumirai mumwe mufananidzo')
        ->and($ndebeleGuidance)->toContain('Umfanekiso umnyama kakhulu');
});
