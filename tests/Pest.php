<?php

use Bernskiold\LaravelImportEngine\Tests\TestCase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(TestCase::class)->in(__DIR__);

/**
 * Write the contents to a temporary file with the extension, and give back
 * the path.
 */
function spreadsheet(string $contents, string $extension = 'csv'): string
{
    $reserved = tempnam(sys_get_temp_dir(), 'import-engine');
    @unlink($reserved);

    $path = $reserved.'.'.$extension;

    file_put_contents($path, $contents);

    return $path;
}

/**
 * Write the rows to a temporary XLSX file, and give back the path.
 *
 * @param  array<int, array<int, mixed>>  $rows
 */
function workbook(array $rows): string
{
    $reserved = tempnam(sys_get_temp_dir(), 'import-engine');
    @unlink($reserved);

    $path = $reserved.'.xlsx';

    $writer = SimpleExcelWriter::create($path)->noHeaderRow();

    foreach ($rows as $row) {
        $writer->addRow($row);
    }

    $writer->close();

    return $path;
}

/**
 * The contents, as an upload that a form receives.
 */
function uploadedSpreadsheet(string $contents, string $name = 'contacts.csv'): File
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}
