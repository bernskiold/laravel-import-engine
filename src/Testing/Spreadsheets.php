<?php

namespace Bernskiold\LaravelImportEngine\Testing;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Makes spreadsheet files for tests.
 *
 * Give the contents as text, or as rows with the headings first:
 *
 *     $path = Spreadsheets::csv("Name,Email\nAda,ada@example.com\n");
 *     $path = Spreadsheets::csv([['Name', 'Email'], ['Ada', 'ada@example.com']]);
 *     $path = Spreadsheets::xlsx([['Name', 'Email'], ['Ada', 'ada@example.com']]);
 *     $upload = Spreadsheets::upload([['Name', 'Email']], 'contacts.xlsx');
 *
 * The files are in the temporary directory, and are deleted when the process
 * stops.
 */
final class Spreadsheets
{
    /**
     * A CSV file, and its path.
     *
     * @param  string|array<int, array<int, mixed>>  $contents
     */
    public static function csv(string|array $contents, string $extension = 'csv'): string
    {
        $path = self::path($extension);

        if (is_string($contents)) {
            file_put_contents($path, $contents);

            return $path;
        }

        $handle = fopen($path, 'w');

        foreach ($contents as $row) {
            fputcsv($handle, array_map(fn (mixed $value): string => (string) $value, $row), escape: '');
        }

        fclose($handle);

        return $path;
    }

    /**
     * An XLSX file, and its path.
     *
     * Each value keeps its type, so a number or a date is a number or a date
     * cell, as in a real workbook.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function xlsx(array $rows): string
    {
        $path = self::path('xlsx');

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();

        foreach ($rows as $row) {
            $writer->addRow($row);
        }

        $writer->close();

        return $path;
    }

    /**
     * An uploaded file, as a form or Livewire receives it.
     *
     * The extension of the name selects CSV or XLSX.
     *
     * @param  string|array<int, array<int, mixed>>  $contents
     */
    public static function upload(string|array $contents, string $name = 'import.csv'): File
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'csv';

        $path = $extension === 'xlsx' && is_array($contents)
            ? self::xlsx($contents)
            : self::csv($contents, $extension);

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    private static function path(string $extension): string
    {
        $reserved = tempnam(sys_get_temp_dir(), 'import-engine');
        @unlink($reserved);

        $path = $reserved.'.'.$extension;

        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }
}
