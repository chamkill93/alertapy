<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_enabled_categories_in_catalog_order(): void
    {
        $this->seed(CategorySeeder::class);
        Category::query()->where('slug', 'security')->update(['enabled' => false]);

        $response = $this->getJson('/api/categories');

        $response
            ->assertOk()
            ->assertJsonCount(13, 'data')
            ->assertJsonPath('data.0.slug', 'electricity')
            ->assertJsonMissing(['slug' => 'security']);
    }
}
