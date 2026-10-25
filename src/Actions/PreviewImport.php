<?php

namespace Bernskiold\LaravelImportEngine\Actions;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Contracts\ImportType;
use Bernskiold\LaravelImportEngine\Exceptions\ImportFileException;
use Bernskiold\LaravelImportEngine\Importer;
use Bernskiold\LaravelImportEngine\Support\ImportContext;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Show a person what the import will do: do it, then roll it back.
 *
 * The full run occurs in a transaction. The transaction is rolled back
 * before this method returns, so no write stays.
 *
 * The alternative is a second implementation that predicts the result.
 * A prediction is incorrect in the cases that are important. For example,
 * it does not know that row 40 conflicts with a record that row 12 makes.
 * It also becomes different from the importer when a person changes the
 * importer. A person clicks "Import" because of the preview, so the preview
 * must be correct.
 *
 * The cost: the work occurs two times, and the transaction stays open for
 * the full run. For a long file, give a limit.
 *
 * The rollback does not undo side effects outside the database, for example
 * an email. An importer can call previewing() to stop them.
 */
class PreviewImport
{
    /**
     * @param  (ImportType&BackedEnum)|Importer  $importer  An import type, or an importer.
     * @param  array<string, string|null>  $map
     * @param  array<string, mixed>  $options
     * @param  int|null  $limit  Read only this number of rows.
     *
     * @throws ImportFileException
     */
    public function execute((ImportType&BackedEnum)|Importer $importer, string $path, array $map = [], array $options = [], ?int $limit = null): ImportReport
    {
        $importer = $importer instanceof Importer ? $importer : $importer->importer();

        $spreadsheet = Spreadsheet::open($path);

        $connections = collect($importer->connections())
            ->map(fn (?string $name): ConnectionInterface => DB::connection($name))
            ->unique(fn (ConnectionInterface $connection): string => (string) $connection->getName())
            ->values();

        /*
         * Do not use DB::transaction(), which commits when the callback
         * returns. The rollback must occur also when the run throws an
         * exception halfway.
         *
         * In a transaction that is already open, as in a test, this is a
         * savepoint. The rollback goes back to the savepoint only.
         */
        $connections->each(fn (ConnectionInterface $connection) => $connection->beginTransaction());

        try {
            return $importer->import(new ImportContext(
                spreadsheet: $spreadsheet,
                map: $map,
                options: $options,
                limit: $limit,
                preview: true,
            ));
        } finally {
            $connections->reverse()->each(fn (ConnectionInterface $connection) => $connection->rollBack());
        }
    }
}
