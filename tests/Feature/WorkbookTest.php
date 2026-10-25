<?php

use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Bernskiold\LaravelImportEngine\Support\Workbook;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;

it('downloads a workbook that the engine can read again', function () {
    $response = Workbook::download('contacts-template.xlsx', (new ContactImporter)->template(), [
        ['Sony', 'sony@example.com', 'CMO'],
        ['Pepsi', 'pepsi@example.com', null],
    ]);

    ob_start();
    $response->sendContent();
    $contents = ob_get_clean();

    $path = spreadsheet($contents, 'xlsx');
    $sheet = Spreadsheet::open($path);

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=contacts-template.xlsx')
        ->and($sheet->headers())->toBe(['name', 'email', 'job_title'])
        ->and($sheet->preview())->toBe([
            ['line' => 2, 'values' => ['name' => 'Sony', 'email' => 'sony@example.com', 'job_title' => 'CMO']],
            ['line' => 3, 'values' => ['name' => 'Pepsi', 'email' => 'pepsi@example.com', 'job_title' => '']],
        ]);
});

it('writes text that starts with an equals sign as text, not as a formula', function () {
    $response = Workbook::download('export.xlsx', ['name'], [['=HYPERLINK("https://example.com")']]);

    ob_start();
    $response->sendContent();
    $path = spreadsheet(ob_get_clean(), 'xlsx');

    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheet)->toContain('t="inlineStr"><is><t>=HYPERLINK(&quot;https://example.com&quot;)</t></is>')
        ->and($sheet)->not->toContain('<f>');
});
