<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('name');
            $table->string('kind', 40)->index();
            $table->string('city')->nullable()->index();
            $table->string('department')->nullable()->index();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_collecting_data')->default(false);
            $table->boolean('is_under_maintenance')->default(false);
            $table->timestamp('last_observed_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('station_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitoring_station_id')->constrained()->cascadeOnDelete();
            $table->string('parameter', 40)->index();
            $table->decimal('value', 14, 5);
            $table->string('unit', 32)->nullable();
            $table->timestamp('measured_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['monitoring_station_id', 'parameter', 'measured_at'], 'station_parameter_time_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_measurements');
        Schema::dropIfExists('monitoring_stations');
    }
};
