<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiProviderContract;
use App\Contracts\EmbeddingProviderContract;
use App\Contracts\MessagingChannelContract;
use App\Contracts\VoiceTranscriberContract;
use App\Contracts\WeatherProviderContract;
use App\Logging\RedactSecretsProcessor;
use App\Services\Ai\Embeddings\GeminiEmbeddingProvider;
use App\Services\Ai\Embeddings\VoyageEmbeddingProvider;
use App\Services\Ai\Providers\AnthropicClaudeProvider;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\GeminiAiProvider;
use App\Services\Ai\Voice\VoiceTranscriptionService;
use App\Services\Channels\LogWhatsAppChannel;
use App\Services\Channels\WhatsAppCloudService;
use App\Services\Weather\ZimbabweWeatherService;
use App\Support\ProductionDatabaseGuard;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());

        $this->app->bind(MessagingChannelContract::class, function ($app) {
            $hasCreds = trim((string) config('betterlink.whatsapp.access_token', '')) !== ''
                && trim((string) config('betterlink.whatsapp.default_phone_number_id', '')) !== '';

            if ($app->environment(['local', 'testing']) || !config('betterlink.whatsapp.enabled') || !$hasCreds) {
                return $app->make(LogWhatsAppChannel::class);
            }

            return $app->make(WhatsAppCloudService::class);
        });

        $this->app->bind(AiProviderContract::class, function ($app) {
            $default = strtolower((string) config('betterlink.ai.default_provider', 'anthropic'));

            if ($default === 'fake') {
                if (!$app->environment(['local', 'testing'])) {
                    throw new RuntimeException('AI_DEFAULT_PROVIDER=fake is forbidden outside local/testing.');
                }

                return $app->make(FakeAiProvider::class);
            }

            if ($default === 'gemini') {
                return $app->make(GeminiAiProvider::class);
            }

            return $app->make(AnthropicClaudeProvider::class);
        });

        $this->app->bind(EmbeddingProviderContract::class, function ($app) {
            $provider = strtolower((string) config('betterlink.ai.embedding_provider', 'voyage'));
            if ($provider === 'gemini') {
                return $app->make(GeminiEmbeddingProvider::class);
            }

            return $app->make(VoyageEmbeddingProvider::class);
        });

        $this->app->bind(VoiceTranscriberContract::class, VoiceTranscriptionService::class);
        $this->app->bind(WeatherProviderContract::class, ZimbabweWeatherService::class);
    }

    public function boot(): void
    {
        $logger = Log::getLogger();
        if (method_exists($logger, 'pushProcessor')) {
            $logger->pushProcessor(new RedactSecretsProcessor());
        }

        Queue::before(function (): void {
            ProductionDatabaseGuard::assertReady();
        });

        RateLimiter::for('planner-api', function (Request $request) {
            $farmerId = $request->attributes->get('planner_farmer_id');

            return Limit::perMinute(60)->by(
                $farmerId ? "farmer:{$farmerId}" : (string) $request->ip()
            );
        });

        RateLimiter::for('planner-otp', function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone', ''));

            return [
                Limit::perMinutes(10, 5)->by($phone !== '' ? "otp_phone:{$phone}" : (string) $request->ip()),
                Limit::perMinutes(10, 15)->by('otp_ip:' . (string) $request->ip()),
                Limit::perMinute(300)->by('otp_global'),
            ];
        });
    }
}
