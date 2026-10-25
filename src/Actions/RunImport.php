<?php

namespace Bernskiold\LaravelImportEngine\Actions;

use Bernskiold\LaravelImportEngine\Events\ImportCompleted;
use Bernskiold\LaravelImportEngine\Events\ImportFailed;
use Bernskiold\LaravelImportEngine\Events\ImportStarted;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Support\ImportContext;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Throwable;

/**
 * Read the file of a record and keep what occurred.
 *
 * This is the body of the queued job. It is not in the job, so that a
 * console command or a test can call it without a queue.
 *
 * There is no transaction around the run. This is the opposite of the
 * preview, and it is intentional. If row three hundred fails, the rows
 * before it are correct and stay imported. Each row is its own transaction,
 * see Importer::transactionPerRow().
 */
class RunImport
{
    /**
     * @throws Throwable
     */
    public function execute(Import $import): ImportReport
    {
        $import->start();

        ImportStarted::dispatch($import);

        try {
            $report = $import->withSpreadsheet(
                fn (Spreadsheet $spreadsheet): ImportReport => $import->importer()->import(new ImportContext(
                    spreadsheet: $spreadsheet,
                    map: $import->column_map ?? [],
                    options: $import->options ?? [],
                    import: $import,
                )),
            );
        } catch (Throwable $exception) {
            /*
             * The file is missing, it is not a spreadsheet, it does not have
             * the required columns, or the importer stopped. Rows that fail
             * do not come here, because they are entries in the report.
             */
            $this->fail($import, $exception);

            throw $exception;
        } finally {
            if (config('import-engine.delete_files_after_run', false)) {
                $import->deleteFile();
            }
        }

        $import->complete($report);

        ImportCompleted::dispatch($import);

        return $report;
    }

    private function fail(Import $import, Throwable $exception): void
    {
        $import->fail($exception->getMessage() ?: __('The import stopped unexpectedly.'));

        ImportFailed::dispatch($import);
    }
}
