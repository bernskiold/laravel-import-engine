<?php

namespace Bernskiold\LaravelImportEngine\Tests;

use Bernskiold\LaravelImportEngine\ImportEngineServiceProvider;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->artisan('migrate')->run();

        (include __DIR__.'/../database/migrations/create_imports_table.php.stub')->up();
    }

    protected function getPackageProviders($app)
    {
        return [
            ImportEngineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('auth.providers.users.model', User::class);
        config()->set('import-engine.types', TestImportType::class);
        config()->set('filesystems.default', 'local');
    }
}
