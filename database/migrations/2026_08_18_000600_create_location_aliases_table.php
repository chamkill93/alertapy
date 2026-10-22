<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('geocoding_query', 255)->nullable()->after('normalized_name');
            $table->string('normalized_query', 255)->nullable()->after('geocoding_query');
            $table->string('city', 160)->nullable()->after('normalized_query');
            $table->string('department', 160)->nullable()->after('city');
            $table->string('accuracy', 24)->default('approximate')->after('department');
            $table->boolean('enabled')->default(true)->after('accuracy');

            $table->index(['normalized_query', 'enabled']);
            $table->index(['department', 'city']);
        });

        Schema::create('location_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('normalized_alias', 200)->unique();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();

            $table->index(['location_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_aliases');

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex(['normalized_query', 'enabled']);
            $table->dropIndex(['department', 'city']);
            $table->dropColumn([
                'geocoding_query',
                'normalized_query',
                'city',
                'department',
                'accuracy',
                'enabled',
            ]);
        });
    }
};
