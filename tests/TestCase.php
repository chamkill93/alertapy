<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Never allow a test using RefreshDatabase to touch the local preview DB.
     */
    public function createApplication(): Application
    {
        // RefreshDatabase is destructive by design. Set every PHP environment
        // adapter before Laravel boots so a shell variable from the preview
        // server can never redirect a test to the persistent local database.
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL=');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

        $app = parent::createApplication();

        if (
            ! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException('Tests must use the isolated SQLite :memory: database.');
        }

        return $app;
    }
}
