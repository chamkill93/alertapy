<?php

namespace Tests\Architecture;

use Tests\TestCase;

class SharedHostingCompatibilityTest extends TestCase
{
    public function test_project_has_no_node_or_vite_runtime_artifacts(): void
    {
        $this->assertFileDoesNotExist(base_path('package.json'));
        $this->assertFileDoesNotExist(base_path('package-lock.json'));
        $this->assertFileDoesNotExist(base_path('vite.config.js'));

        $composer = file_get_contents(base_path('composer.json'));
        $this->assertIsString($composer);
        $this->assertStringNotContainsString('npm ', $composer);
        $this->assertStringNotContainsString('npx ', $composer);
        $this->assertStringNotContainsString('laravel/sail', $composer);
        $this->assertStringNotContainsString('laravel/pail', $composer);
    }

    public function test_production_configuration_uses_mysql_and_sync_work_only(): void
    {
        $environment = file_get_contents(base_path('.env.example'));

        $this->assertIsString($environment);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $environment);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $environment);
        $this->assertStringNotContainsString('REDIS_', $environment);
    }
}
