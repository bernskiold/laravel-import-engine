<?php

use Bernskiold\LaravelImportEngine\Enums\ImportStatus;
use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Storage;

function storedImport(array $attributes = []): Import
{
    Storage::disk('local')->put('imports/contacts.csv', "Name,Email\nSony,sony@example.com\n");

    return Import::query()->create([
        'type' => TestImportType::Contacts,
        'file_name' => 'contacts.csv',
        'file_path' => 'imports/contacts.csv',
        'disk' => 'local',
        ...$attributes,
    ]);
}

it('is pending when it is made', function () {
    Storage::fake('local');

    $import = storedImport();

    expect($import->refresh()->status)->toBe(ImportStatus::Pending)
        ->and($import->isPending())->toBeTrue()
        ->and($import->isSettled())->toBeFalse()
        ->and($import->getRouteKeyName())->toBe('uuid')
        ->and($import->getKey())->toBeInt();
});

it('moves through the states', function () {
    Storage::fake('local');

    $import = storedImport();

    $import->start();
    expect($import->isProcessing())->toBeTrue();

    $import->complete((new ImportReport)->created(2, 'Sony'));
    expect($import->isCompleted())->toBeTrue()
        ->and($import->isSettled())->toBeTrue()
        ->and($import->hasReport())->toBeTrue()
        ->and($import->importReport()->rows())->toBe(1);
});

it('keeps the report when it fails', function () {
    Storage::fake('local');

    $import = storedImport();
    $import->complete((new ImportReport)->created(2, 'Sony'));
    $import->fail('Stopped.');

    expect($import->isFailed())->toBeTrue()
        ->and($import->failure_reason)->toBe('Stopped.')
        ->and($import->importReport()->rows())->toBe(1);
});

it('gives an empty report when there is none', function () {
    Storage::fake('local');

    expect(storedImport()->importReport()->rows())->toBe(0);
});

it('finds the importer and the label of its type', function () {
    Storage::fake('local');

    $import = storedImport();

    expect($import->importer())->toBeInstanceOf(ContactImporter::class)
        ->and($import->importType())->toBe(TestImportType::Contacts)
        ->and($import->label())->toBe('Contacts – contacts.csv');
});

it('gives a local copy of the file with its extension, and deletes the copy', function () {
    Storage::fake('local');

    $import = storedImport();

    $copy = $import->withLocalFile(function (string $path): string {
        expect($path)->toEndWith('.csv')
            ->and(file_get_contents($path))->toStartWith('Name,Email');

        return $path;
    });

    expect(file_exists($copy))->toBeFalse();
});

it('opens the file as a spreadsheet', function () {
    Storage::fake('local');

    $headers = storedImport()->withSpreadsheet(fn (Spreadsheet $spreadsheet): array => $spreadsheet->headers());

    expect($headers)->toBe(['Name', 'Email']);
});

it('stops when the file is not there', function () {
    Storage::fake('local');

    $import = storedImport();
    Storage::disk('local')->delete('imports/contacts.csv');

    expect($import->hasFile())->toBeFalse();

    $import->withLocalFile(fn (string $path) => $path);
})->throws(UnreadableFileException::class);

it('deletes the file with the record', function () {
    Storage::fake('local');

    storedImport()->delete();

    expect(Storage::disk('local')->exists('imports/contacts.csv'))->toBeFalse();
});

it('belongs to the person who started it', function () {
    Storage::fake('local');

    $user = User::query()->create(['name' => 'Ada']);

    expect(storedImport(['user_id' => $user->id])->user->is($user))->toBeTrue();
});

it('finds the imports of a type and of a person, the latest first', function () {
    Storage::fake('local');

    $user = User::query()->create(['name' => 'Ada']);

    $first = storedImport(['user_id' => $user->id]);
    $second = storedImport(['user_id' => $user->id]);
    storedImport(['type' => TestImportType::Sectors]);

    expect(Import::query()->ofType(TestImportType::Contacts)->count())->toBe(2)
        ->and(Import::query()->ofType('sectors')->count())->toBe(1)
        ->and(Import::query()->startedBy($user)->latestFirst()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('uses the configured table', function () {
    config()->set('import-engine.table', 'other_imports');

    expect((new Import)->getTable())->toBe('other_imports');
});
