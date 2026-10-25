<?php

use Bernskiold\LaravelImportEngine\Models\Import;

return [

    /*
     * The enum that lists the import types of the application.
     *
     * It must be a string-backed enum that implements
     * Bernskiold\LaravelImportEngine\Contracts\ImportType.
     * The engine stores the enum value in the "type" column.
     */
    'types' => null,

    /*
     * The model that records each import. Extend the package model to add
     * columns, relations or scopes of your own, and set your class here.
     */
    'model' => Import::class,

    /*
     * The table that the import model uses.
     */
    'table' => 'imports',

    /*
     * The user model that the import record belongs to.
     * Leave null to use the model of the default auth provider.
     */
    'user_model' => null,

    /*
     * The disk and directory for uploaded files. Leave the disk null to use
     * the default filesystem disk. The disk name is kept on each record, so
     * a file stays readable after you change this setting.
     */
    'disk' => null,

    'directory' => 'imports',

    /*
     * Delete the uploaded file when the run stops, if it completed or failed.
     * Set this to true for files that contain personal data.
     */
    'delete_files_after_run' => false,

    /*
     * The text encoding of a CSV file that has no byte order mark and is not
     * valid UTF-8. Excel on Windows saves CSV files in Windows-1252.
     */
    'csv_fallback_encoding' => 'Windows-1252',

    /*
     * The order of a date such as "03/04/2026": "dmy" reads the 3rd of April,
     * "mdy" reads the 4th of March. See ImportRow::date().
     */
    'date_order' => 'dmy',

    /*
     * Write the progress of a run to the record after this number of rows.
     * A screen can poll the record and show the progress.
     */
    'progress_interval' => 50,

    /*
     * Delete the settled imports, and their files, after this number of days.
     * Leave null to keep them. Schedule the model:prune command with the
     * import model, for example:
     *
     *     Schedule::command('model:prune', ['--model' => [Import::class]])->daily();
     */
    'prune_after_days' => null,

    /*
     * Send the exception of a row that failed to the exception handler.
     * The report always records the row as failed.
     */
    'report_row_failures' => true,

    /*
     * The queue that runs each import, and the maximum time in seconds that
     * one run can take. Leave connection and queue null to use the defaults.
     */
    'queue' => [
        'connection' => null,
        'queue' => null,
        'timeout' => 900,
    ],

];
