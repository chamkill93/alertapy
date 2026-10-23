<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Solo catálogos verificables. AlertaPY nunca siembra incidencias de demostración.
        $this->call([
            CategorySeeder::class,
            SourceSeeder::class,
            AdditionalPressSourceSeeder::class,
            RegionalPressSourceSeeder::class,
            LocationSeeder::class,
        ]);
    }
}
