<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

/**
 * The file, or the column map, does not supply all the required columns.
 */
class MissingColumnsException extends ImportFileException
{
    /**
     * @param  array<int, string>  $columns  The labels of the missing columns.
     */
    public function __construct(
        public readonly array $columns,
    ) {
        parent::__construct(__('These required columns are missing: :columns.', [
            'columns' => implode(', ', $columns),
        ]));
    }
}
