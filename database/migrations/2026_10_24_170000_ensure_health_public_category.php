<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('categories')->insertOrIgnore([
            'name' => 'Salud pública',
            'slug' => 'health_public',
            'icon' => '✚',
            'color' => '#0F766E',
            'description' => 'Alertas epidemiológicas, atención y servicios de salud con ubicación territorial.',
            'enabled' => true,
            'sort_order' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the catalog row: published incidents may already reference it.
    }
};
