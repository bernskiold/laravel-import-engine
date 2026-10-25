<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

use Throwable;

/**
 * The engine can not read the file.
 *
 * This is different from a row that fails. A row that fails is an entry in
 * the report. This exception means that there is no report, because there
 * is nothing to read.
 */
class UnreadableFileException extends ImportFileException
{
    public static function missing(string $path): self
    {
        return new self(__('The uploaded file could not be found. Upload it again.'));
    }

    public static function withoutHeaders(string $path): self
    {
        return new self(__('No column headings were found in this file. The first row with more than one filled cell is read as the headings. Make sure that the file is a table.'));
    }

    public static function unparseable(string $path, ?Throwable $previous = null): self
    {
        return new self(
            __('This file could not be opened as a spreadsheet. You can import CSV and Excel (.xlsx) files.'),
            previous: $previous,
        );
    }
}
