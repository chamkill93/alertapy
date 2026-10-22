<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('representation_type', 24)->default('point')->after('border_status')->index();
            $table->json('geometry_geojson')->nullable()->after('representation_type');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropIndex(['representation_type']);
            $table->dropColumn(['representation_type', 'geometry_geojson']);
        });
    }
};
