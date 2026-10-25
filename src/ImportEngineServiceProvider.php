<?php

namespace Bernskiold\LaravelImportEngine;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class ImportEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/import-engine.php', 'import-engine'
        );
    }

    public function boot(): void
    {
        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('Laravel Import Engine', fn () => [
                'Types' => config('import-engine.types') ?? '<fg=yellow;options=bold>NOT SET</>',
                'Model' => ImportEngine::model(),
                'Disk' => ImportEngine::disk(),
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/import-engine.php' => config_path('import-engine.php'),
        ], 'import-engine-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_imports_table.php.stub' => $this->app->databasePath(
                'migrations/'.date('Y_m_d_His').'_create_imports_table.php'
            ),
        ], 'import-engine-migrations');
    }
}
