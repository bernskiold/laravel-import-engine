<?php

use Bernskiold\LaravelImportEngine\Actions\PreviewImport;
use Bernskiold\LaravelImportEngine\Actions\RunImport;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Illuminate\Support\Facades\Storage;

/**
 * Store a file with the number of contacts and make a pending record.
 */
function importOfContacts(int $rows): Import
{
    $lines = collect(range(1, $rows))->map(fn (int $row): string => "Contact {$row},contact{$row}@example.com")->implode("\n");

    Storage::disk('local')->put('imports/contacts.csv', "Name,Email\n{$lines}\n");

    return Import::query()->create([
        'type' => TestImportType::Contacts,
        'file_name' => 'contacts.csv',
        'file_path' => 'imports/contacts.csv',
        'disk' => 'local',
    ]);
}

it('keeps the total and the progress of a run', function () {
    Storage::fake('local');

    $import = importOfContacts(5);

    expect($import->progress())->toBeNull();

    app(RunImport::class)->execute($import);

    $import->refresh();

    expect($import->total_rows)->toBe(5)
        ->and($import->processed_rows)->toBe(5)
        ->and($import->progress())->toBe(1.0);
});

it('writes the progress at the interval while the run reads', function () {
    Storage::fake('local');
    config()->set('import-engine.progress_interval', 2);

    $importer = new class extends ContactImporter
    {
        /**
         * The progress on the record when each row starts.
         *
         * @var array<int, int>
         */
        public static array $seen = [];

        protected function importRow(ImportRow $row, ImportReport $report): void
        {
            self::$seen[] = Import::query()->sole()->processed_rows;

            parent::importRow($row, $report);
        }
    };

    app()->instance(ContactImporter::class, $importer);

    app(RunImport::class)->execute(importOfContacts(5));

    expect($importer::$seen)->toBe([0, 2, 2, 4, 4]);
});

it('gives the part of the file that was read', function () {
    $import = new Import(['total_rows' => 4, 'processed_rows' => 1]);

    expect($import->progress())->toBe(0.25);
});

it('does not write progress in a preview', function () {
    Storage::fake('local');

    $import = importOfContacts(3);

    app(PreviewImport::class)->execute(TestImportType::Contacts, Storage::disk('local')->path('imports/contacts.csv'));

    expect($import->refresh()->processed_rows)->toBe(0);
});
