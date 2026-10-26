<?php

namespace Bernskiold\LaravelImportEngine\Tests;

use Bernskiold\LaravelImportEngine\ImportEngineServiceProvider;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * The tests run on SQLite. Set DB_CONNECTION to "mysql" or "pgsql", with
     * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD, to run them
     * on that database.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Import::forgetColumnListings();
    }

    protected function getPackageProviders($app)
    {
        return [
            ImportEngineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', match ($driver) {
            'mysql', 'pgsql' => [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $driver === 'mysql' ? 3306 : 5432),
                'database' => env('DB_DATABASE', 'import_engine_testing'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        });

        config()->set('auth.providers.users.model', User::class);
        config()->set('import-engine.types', TestImportType::class);
        config()->set('filesystems.default', 'local');
    }
}
