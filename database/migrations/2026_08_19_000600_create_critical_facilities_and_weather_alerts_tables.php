<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_facilities', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 160);
            $table->string('kind', 32)->index();
            $table->string('name', 255);
            $table->string('category', 160)->nullable();
            $table->string('department', 120)->nullable()->index();
            $table->string('district', 160)->nullable()->index();
            $table->string('neighborhood', 160)->nullable();
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->string('source_name', 160);
            $table->string('source_url', 2048);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['kind', 'external_id']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('weather_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 255)->unique();
            $table->string('event', 160);
            $table->string('headline', 500);
            $table->text('description')->nullable();
            $table->string('severity', 32)->nullable()->index();
            $table->string('urgency', 32)->nullable();
            $table->string('certainty', 32)->nullable();
            $table->string('area_description', 1000)->nullable();
            $table->json('geometry_geojson')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('source_url', 2048);
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_alerts');
        Schema::dropIfExists('critical_facilities');
    }
};
