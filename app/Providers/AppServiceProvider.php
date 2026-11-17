<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiProviderContract;
use App\Contracts\EmbeddingProviderContract;
use App\Contracts\MessagingChannelContract;
use App\Contracts\VoiceTranscriberContract;
use App\Contracts\WeatherProviderContract;
use App\Services\Ai\Embeddings\VoyageEmbeddingProvider;
use App\Services\Ai\Providers\AnthropicClaudeProvider;
use App\Services\Ai\Voice\VoiceTranscriptionService;
use App\Services\Channels\WhatsAppCloudService;
use App\Services\Weather\ZimbabweWeatherService;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
        $this->app->bind(MessagingChannelContract::class, WhatsAppCloudService::class);
        $this->app->bind(AiProviderContract::class, AnthropicClaudeProvider::class);
        $this->app->bind(EmbeddingProviderContract::class, VoyageEmbeddingProvider::class);
        $this->app->bind(VoiceTranscriberContract::class, VoiceTranscriptionService::class);
        $this->app->bind(WeatherProviderContract::class, ZimbabweWeatherService::class);
    }

    public function boot(): void
    {
        //
    }
}
