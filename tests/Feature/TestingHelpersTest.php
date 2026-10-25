<?php

use Bernskiold\LaravelImportEngine\Actions\StartImport;
use Bernskiold\LaravelImportEngine\Jobs\RunImportJob;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Bernskiold\LaravelImportEngine\Testing\Spreadsheets;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;

it('makes csv and xlsx files from rows', function () {
    $csv = Spreadsheet::open(Spreadsheets::csv([['Name', 'Notes'], ['Sony', 'A, "quoted" note']]));
    $xlsx = Spreadsheet::open(Spreadsheets::xlsx([['Name', 'Rank'], ['Sony', 4]]));

    expect($csv->preview()[0]['values'])->toBe(['Name' => 'Sony', 'Notes' => 'A, "quoted" note'])
        ->and($xlsx->preview()[0]['values'])->toBe(['Name' => 'Sony', 'Rank' => '4']);
});

it('makes an upload of each type', function () {
    $csv = Spreadsheets::upload("Name,Email\nSony,sony@example.com\n", 'contacts.csv');
    $xlsx = Spreadsheets::upload([['Name', 'Email'], ['Sony', 'sony@example.com']], 'contacts.xlsx');

    $xlsxCopy = sys_get_temp_dir().'/'.uniqid('upload').'.xlsx';
    copy($xlsx->getRealPath(), $xlsxCopy);

    expect($csv->getClientOriginalName())->toBe('contacts.csv')
        ->and($xlsx->getClientOriginalName())->toBe('contacts.xlsx')
        ->and(Spreadsheet::open($xlsxCopy)->headers())->toBe(['Name', 'Email']);
});

it('fakes the start of an import', function () {
    Queue::fake();
    Storage::fake('local');

    $imports = StartImport::fake();

    $import = app(StartImport::class)->execute(TestImportType::Contacts, Spreadsheets::upload("Name,Email\n"), options: ['list' => 4]);

    expect($import->exists)->toBeTrue()
        ->and(Storage::disk('local')->exists($import->file_path))->toBeTrue();

    $imports->assertStarted(TestImportType::Contacts);
    $imports->assertStarted(TestImportType::Contacts, fn (Import $import): bool => $import->options['list'] === 4);
    $imports->assertStartedTimes(TestImportType::Contacts, 1);
    $imports->assertNotStarted(TestImportType::Sectors);

    Queue::assertNotPushed(RunImportJob::class);
});

it('fails the assertions when nothing agrees', function () {
    $imports = StartImport::fake();

    $imports->assertNothingStarted();

    expect(fn () => $imports->assertStarted(TestImportType::Contacts))->toThrow(AssertionFailedError::class);
});
