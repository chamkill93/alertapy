<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('discovered')->default(0);
            $table->unsignedInteger('created_candidates')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'started_at']);
        });

        Schema::create('raw_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_identifier', 191)->nullable();
            $table->string('original_url', 2048);
            $table->string('title', 500);
            $table->longText('raw_content');
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('detected_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source_id', 'content_hash'], 'raw_source_hash_unique');
            $table->unique(['source_id', 'external_identifier'], 'raw_source_external_unique');
        });

        Schema::create('content_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 20)->index();
            $table->string('status', 32)->default('pending_ai')->index();
            $table->longText('normalized_content');
            $table->string('detected_location', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('ai_status', 32)->nullable()->index();
            $table->string('ai_decision', 16)->nullable()->index();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->longText('ai_raw_response')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['source_id', 'created_at']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_candidates');
        Schema::dropIfExists('raw_items');
        Schema::dropIfExists('source_runs');
    }
};
