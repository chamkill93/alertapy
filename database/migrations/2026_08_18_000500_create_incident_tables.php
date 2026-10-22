<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title', 220);
            $table->text('summary');
            $table->string('source_type', 20)->index();
            $table->string('verification_level', 32)->index();
            $table->string('status', 32)->index();
            $table->string('severity', 16)->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_accuracy', 24);
            $table->string('department', 120)->nullable()->index();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable()->index();
            $table->string('neighborhood', 160)->nullable();
            $table->string('location_text', 255);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'status', 'published_at'], 'inc_category_status_published');
            $table->index(['latitude', 'longitude']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('incident_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('raw_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_title', 500);
            $table->string('original_url', 2048);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['incident_id', 'raw_item_id'], 'incident_raw_unique');
            $table->index(['incident_id', 'published_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('raw_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 220);
            $table->text('summary');
            $table->string('status', 32)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['incident_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incident_sources');
        Schema::dropIfExists('incidents');
    }
};
