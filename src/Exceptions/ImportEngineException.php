<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

use LogicException;

/**
 * The engine, or an importer, is not configured correctly.
 */
class ImportEngineException extends LogicException
{
    public static function typesNotConfigured(): self
    {
        return new self('Set "import-engine.types" to a string-backed enum that implements Bernskiold\LaravelImportEngine\Contracts\ImportType.');
    }

    public static function invalidTypes(string $class): self
    {
        return new self("The import types class [{$class}] must be a string-backed enum that implements Bernskiold\\LaravelImportEngine\\Contracts\\ImportType.");
    }

    public static function unknownType(string $value): self
    {
        return new self("There is no import type with the value [{$value}].");
    }

    public static function nothingToImport(string $importer): self
    {
        return new self("The importer [{$importer}] must override importRow() or handle().");
    }
}
