<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('type', 24);
            $table->string('name', 160);
            $table->string('normalized_name', 160);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'type']);
            $table->index(['normalized_name', 'type']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('geocoding_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 255);
            $table->string('normalized_query', 255)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('precision', 24);
            $table->string('provider', 80);
            $table->string('provider_reference', 255)->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocoding_cache');
        Schema::dropIfExists('locations');
    }
};
