<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electricidad', 'slug' => 'electricity', 'icon' => '⚡', 'color' => '#E11D48', 'description' => 'Cortes, trabajos y novedades del servicio eléctrico.'],
            ['name' => 'Agua', 'slug' => 'water', 'icon' => '💧', 'color' => '#0284C7', 'description' => 'Interrupciones y novedades del servicio de agua.'],
            ['name' => 'Seguridad', 'slug' => 'security', 'icon' => '🛡', 'color' => '#1D4ED8', 'description' => 'Hechos de seguridad con utilidad territorial.'],
            ['name' => 'Accidentes y tránsito', 'slug' => 'traffic_accident', 'icon' => '🚗', 'color' => '#DC2626', 'description' => 'Accidentes y afectaciones al tránsito.'],
            ['name' => 'Incendios', 'slug' => 'fire', 'icon' => '🔥', 'color' => '#EA580C', 'description' => 'Incendios e intervenciones relacionadas.'],
            ['name' => 'Inundaciones y clima', 'slug' => 'weather_flood', 'icon' => '🌧', 'color' => '#2563EB', 'description' => 'Alertas meteorológicas e inundaciones.'],
            ['name' => 'Calles, rutas, bloqueos y obras', 'slug' => 'roads', 'icon' => '🚧', 'color' => '#D97706', 'description' => 'Bloqueos, cierres, desvíos y obras.'],
            ['name' => 'Fronteras y pasos', 'slug' => 'borders', 'icon' => '🛂', 'color' => '#0B6E99', 'description' => 'Cambios operativos, restricciones y cierres de pasos fronterizos.'],
            ['name' => 'Salud y SENEPA', 'slug' => 'health_vector', 'icon' => '🦟', 'color' => '#16A34A', 'description' => 'Intervenciones territoriales de salud y control vectorial.'],
            ['name' => 'Salud animal', 'slug' => 'animal_health', 'icon' => '🐾', 'color' => '#00A36C', 'description' => 'Alertas, campañas y operativos oficiales de sanidad animal.'],
            ['name' => 'Ríos e hidrología', 'slug' => 'hydrology', 'icon' => '🌊', 'color' => '#009C98', 'description' => 'Niveles, alertas y novedades hidrológicas.'],
            ['name' => 'Calidad del aire', 'slug' => 'air_quality', 'icon' => '🌫', 'color' => '#7F8C99', 'description' => 'Información territorial sobre la calidad del aire.'],
            ['name' => 'Emergencias', 'slug' => 'emergency', 'icon' => '🚨', 'color' => '#BE123C', 'description' => 'Emergencias territoriales de interés público.'],
            ['name' => 'Novedades territoriales', 'slug' => 'territorial_news', 'icon' => '📰', 'color' => '#059669', 'description' => 'Otras novedades con relevancia territorial.'],
        ];

        foreach ($categories as $order => $category) {
            Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'enabled' => true, 'sort_order' => $order + 1],
            );
        }
    }
}
