<?php

use Bernskiold\LaravelImportEngine\Testing\Spreadsheets;
use Bernskiold\LaravelImportEngine\Tests\TestCase;
use Illuminate\Http\Testing\File;

uses(TestCase::class)->in(__DIR__);

function spreadsheet(string $contents, string $extension = 'csv'): string
{
    return Spreadsheets::csv($contents, $extension);
}

/**
 * @param  array<int, array<int, mixed>>  $rows
 */
function workbook(array $rows): string
{
    return Spreadsheets::xlsx($rows);
}

function uploadedSpreadsheet(string $contents, string $name = 'contacts.csv'): File
{
    return Spreadsheets::upload($contents, $name);
}
