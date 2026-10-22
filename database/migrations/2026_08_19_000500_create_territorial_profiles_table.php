<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territorial_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 24)->default('district')->index();
            $table->string('ine_department_code', 8);
            $table->string('ine_district_code', 8);
            $table->string('department', 120)->index();
            $table->string('district', 160)->index();
            $table->string('normalized_department', 120)->index();
            $table->string('normalized_district', 160)->index();
            $table->unsignedBigInteger('population');
            $table->unsignedSmallInteger('census_year')->default(2022);
            $table->string('source_name', 160)->default('Instituto Nacional de Estadística');
            $table->string('source_url', 2048);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['ine_department_code', 'ine_district_code'],
                'territorial_profile_ine_codes_unique',
            );
            $table->index(
                ['normalized_department', 'normalized_district'],
                'territorial_profile_name_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territorial_profiles');
    }
};
