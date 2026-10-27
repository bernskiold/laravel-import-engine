<?php

use Bernskiold\LaravelImportEngine\Exceptions\ImportEngineException;
use Bernskiold\LaravelImportEngine\ImportEngine;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\User;

it('finds the type from its value', function () {
    expect(ImportEngine::type('contacts'))->toBe(TestImportType::Contacts);
});

it('stops for a value that is not a type', function () {
    ImportEngine::type('invented');
})->throws(ImportEngineException::class);

it('stops when the types are not configured', function () {
    config()->set('import-engine.types', null);

    ImportEngine::type('contacts');
})->throws(ImportEngineException::class);

it('stops when the types are not an import type enum', function () {
    config()->set('import-engine.types', User::class);

    ImportEngine::types();
})->throws(ImportEngineException::class);

it('uses the auth user model when no user model is set', function () {
    expect(ImportEngine::userModel())->toBe(User::class);

    config()->set('import-engine.user_model', 'App\\Models\\Admin');

    expect(ImportEngine::userModel())->toBe('App\\Models\\Admin');
});

it('uses the default disk when no disk is set', function () {
    config()->set('filesystems.default', 's3');

    expect(ImportEngine::disk())->toBe('s3');

    config()->set('import-engine.disk', 'imports');

    expect(ImportEngine::disk())->toBe('imports');
});

it('uses the model that is configured', function () {
    expect(ImportEngine::model())->toBe(Import::class)
        ->and(ImportEngine::query()->getModel())->toBeInstanceOf(Import::class);
});
