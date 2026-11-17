<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('channel', 30)->default('whatsapp'); // whatsapp|web_chat|sms|ussd|flutter_app
            $table->string('external_thread_id')->nullable();
            $table->string('state', 50)->default('CONSENT_CHECK')->index();
            $table->string('mode', 30)->default('ai_active')->index(); // ai_active|human_takeover
            $table->string('language', 10)->default('en'); // en|sn|nd
            $table->string('context_chip')->nullable(); // e.g. "Pepper / fruiting / humid"
            $table->unsignedTinyInteger('poor_photo_retries')->default(0);
            $table->jsonb('pending_message_buffer_json')->nullable();
            $table->jsonb('context_metadata_json')->nullable();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('state_updated_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'farmer_id', 'state']);
            $table->index(['tenant_id', 'mode', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('direction', 15); // inbound|outbound
            $table->string('sender_type', 25); // farmer|ai_agent|sales_rep|agronomist|outlet_manager|system
            $table->string('external_message_id')->nullable()->unique();
            $table->string('message_type', 25)->default('text'); // text|image|audio|location|interactive|rich_card
            $table->string('card_type', 40)->nullable(); // diagnosis_result|recommended_product|application_guidance|profile_updated|event_invite|outlet_list|handoff_summary
            $table->jsonb('card_payload_json')->nullable();
            $table->text('body_text')->nullable();
            $table->text('transcribed_text')->nullable();
            $table->string('detected_language', 10)->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('disk', 30)->default('local');
            $table->string('file_path');
            $table->string('mime_type', 80);
            $table->unsignedInteger('file_size_bytes')->default(0);
            $table->string('sha256_hash', 64)->index();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('blur_score', 8, 2)->nullable();
            $table->decimal('brightness_score', 8, 2)->nullable();
            $table->boolean('quality_passed')->default(true);
            $table->string('quality_failure_reason', 60)->nullable();
            $table->boolean('exif_stripped')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
