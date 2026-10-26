<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

/**
 * The run of the import already started, or it already stopped.
 *
 * Each record runs one time. To import the file again, start a new import.
 */
class ImportAlreadyStartedException extends ImportEngineException
{
    public static function for(int|string $id): self
    {
        return new self("The import [{$id}] already started. Start a new import to run the file again.");
    }
}
