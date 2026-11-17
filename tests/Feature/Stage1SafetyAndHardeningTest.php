<?php

declare(strict_types=1);

use App\Contracts\AiProviderContract;
use App\Contracts\MessagingChannelContract;
use App\Enums\ConversationState;
use App\Enums\HandoffType;
use App\Logging\RedactSecretsProcessor;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Handoff;
use App\Models\Tenant;
use App\Services\Ai\Providers\AnthropicClaudeProvider;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Channels\LogWhatsAppChannel;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Support\ProductionDatabaseGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('fails closed in production when AI provider is unconfigured, forbids fake provider, and escalates to agronomist with no advice', function (): void {
    config()->set('betterlink.ai.anthropic.api_key', '');
    config()->set('betterlink.ai.default_provider', 'anthropic');

    $provider = app(AnthropicClaudeProvider::class);
    expect(fn () => $provider->analyzeImageForDiagnosis('', 'text/plain', 'sys', 'maize leaves'))
        ->toThrow(\RuntimeException::class, 'Anthropic API key is not configured.');
    expect(fn () => $provider->chatWithTools('sys', [['role' => 'user', 'content' => 'help']]))
        ->toThrow(\RuntimeException::class, 'Anthropic API key is not configured.');

    // Production must reject AI_DEFAULT_PROVIDER=fake
    app()->detectEnvironment(fn () => 'production');
    try {
        config()->set('betterlink.ai.default_provider', 'fake');
        app()->forgetInstance(AiProviderContract::class);

        expect(fn () => app(AiProviderContract::class))
            ->toThrow(\RuntimeException::class, 'AI_DEFAULT_PROVIDER=fake is forbidden');

        expect(ProductionDatabaseGuard::status()['ai_provider_safe'])->toBeFalse();
        expect(ProductionDatabaseGuard::status()['ok'])->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        config()->set('betterlink.ai.default_provider', 'fake');
        app()->forgetInstance(AiProviderContract::class);
    }

    // State machine catches provider failure, creates agronomist escalation, and sends no advice
    app()->bind(AiProviderContract::class, AnthropicClaudeProvider::class);
    $tenant = Tenant::query()->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771888111', 'Fail Closed Farmer');
    \App\Models\Consent::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'status' => 'opted_in',
        'data_processing_consent' => true,
        'marketing_consent' => false,
        'policy_version' => '2026.1',
        'consented_at' => now(),
    ]);
    $conv = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::CONSENT_CHECK,
        'language' => 'en',
        'last_message_at' => now(),
    ]);

    \Illuminate\Support\Facades\Storage::disk('local')->put('test_leaf.jpg', 'fake-jpeg-bytes');
    $msg = \App\Models\Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'image',
        'body_text' => 'Please diagnose this maize leaf',
    ]);
    $attachment = \App\Models\Attachment::query()->create([
        'tenant_id' => $tenant->id,
        'message_id' => $msg->id,
        'disk' => 'local',
        'file_path' => 'test_leaf.jpg',
        'mime_type' => 'image/jpeg',
        'file_size_bytes' => 15,
        'sha256_hash' => hash('sha256', 'fake-jpeg-bytes'),
        'width' => 800,
        'height' => 600,
        'quality_passed' => true,
    ]);

    $sm = app(ConversationStateMachine::class);
    $reply = $sm->handleInboundTurn($conv, $msg, $attachment);

    expect($reply['state'])->toBe(ConversationState::HUMAN_TAKEOVER)
        ->and($reply['reply_text'])->toBe('I cannot analyse this right now. Please try again shortly, or ask for an agronomist.')
        ->and(Handoff::query()->where('conversation_id', '=', $conv->id)->where('handoff_type', '=', HandoffType::AGRONOMIST->value)->exists())->toBeTrue();

    app()->bind(AiProviderContract::class, FakeAiProvider::class);
});

it('redacts API keys, tokens, and passwords in log records', function (): void {
    $processor = new RedactSecretsProcessor();
    $record = new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'testing',
        level: Level::Error,
        message: 'Failed with sk-ant-api03-secret1234567890 and AIzaSyDummyKeyValue1234567890123 and Bearer abcdefghijklmnop123456',
        context: ['api_key' => 'super-secret-value', 'nested' => ['password' => 'my_db_pass']],
    );

    $redacted = $processor($record);

    expect($redacted->message)->not->toContain('sk-ant-api03')
        ->and($redacted->message)->not->toContain('AIzaSyDummyKeyValue')
        ->and($redacted->message)->not->toContain('abcdefghijklmnop123456')
        ->and($redacted->context['api_key'])->toBe('[REDACTED]')
        ->and($redacted->context['nested']['password'])->toBe('[REDACTED]');
});

it('binds LogWhatsAppChannel in local/testing and enforces OTP daily cap + HttpOnly SameSite cookie', function (): void {
    expect(app(MessagingChannelContract::class))->toBeInstanceOf(LogWhatsAppChannel::class);

    $phone = '+263771888222';
    // 5 OTP requests within 10 minutes succeed; 6th hits per-phone 10-minute rate limit
    $lastOtp = null;
    for ($i = 0; $i < 5; $i++) {
        $res = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => $phone])->assertStatus(200);
        $lastOtp = $res->json('debug_otp');
    }
    $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => $phone])->assertStatus(429);

    // Daily cap (10/day) also blocks even when per-minute/10-minute bucket is fresh
    $dailyPhone = '+263771888333';
    \Illuminate\Support\Facades\Cache::put(
        'planner_otp_daily:' . Farmer::hashPhone($dailyPhone) . ':' . now()->toDateString(),
        10,
        now()->endOfDay()
    );
    $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => $dailyPhone])->assertStatus(429);

    $verifyPhone = '+263771888444';
    $otpRes = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => $verifyPhone])->assertStatus(200);
    $verify = $this->postJson('/api/v1/planner/auth/verify-otp', [
        'phone' => $verifyPhone,
        'otp' => $otpRes->json('debug_otp'),
    ])->assertStatus(200);

    $cookie = $verify->getCookie('planner_token', false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax');
});

it('marks sn and nd translations as needs_native_review and disables them by default', function (): void {
    expect(config('betterlink.localization.enable_sn_nd'))->toBeFalse()
        ->and(config('betterlink.localization.translation_review_status.sn'))->toBe('needs_native_review')
        ->and(config('betterlink.localization.translation_review_status.nd'))->toBe('needs_native_review');
});
