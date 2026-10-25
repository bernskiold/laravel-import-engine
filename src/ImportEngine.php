<?php

namespace Bernskiold\LaravelImportEngine;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Contracts\ImportType;
use Bernskiold\LaravelImportEngine\Exceptions\ImportEngineException;
use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads the configuration of the engine.
 */
final class ImportEngine
{
    /**
     * @return class-string<Import>
     */
    public static function model(): string
    {
        /** @var class-string<Import> */
        return config('import-engine.model') ?? Import::class;
    }

    /**
     * A new query for the configured import model.
     *
     * @return Builder<Import>
     */
    public static function query(): Builder
    {
        return self::model()::query();
    }

    /**
     * The enum of the import types, when it is configured.
     *
     * @return class-string<ImportType&BackedEnum>|null
     *
     * @throws ImportEngineException
     */
    public static function types(): ?string
    {
        $class = config('import-engine.types');

        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! enum_exists($class) || ! is_subclass_of($class, ImportType::class) || ! is_subclass_of($class, BackedEnum::class)) {
            throw ImportEngineException::invalidTypes(is_string($class) ? $class : get_debug_type($class));
        }

        /** @var class-string<ImportType&BackedEnum> $class */
        return $class;
    }

    /**
     * The import type with the stored value.
     *
     * @throws ImportEngineException
     */
    public static function type(string $value): ImportType&BackedEnum
    {
        $class = self::types() ?? throw ImportEngineException::typesNotConfigured();

        return $class::tryFrom($value) ?? throw ImportEngineException::unknownType($value);
    }

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        /** @var class-string<Model> */
        return config('import-engine.user_model')
            ?? config('auth.providers.'.config('auth.guards.'.config('auth.defaults.guard', 'web').'.provider', 'users').'.model')
            ?? config('auth.providers.users.model');
    }

    /**
     * The disk for uploaded files.
     */
    public static function disk(): string
    {
        return (string) (config('import-engine.disk') ?? config('filesystems.default'));
    }

    public static function directory(): string
    {
        return trim((string) config('import-engine.directory', 'imports'), '/');
    }
}
