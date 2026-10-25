<?php

use Bernskiold\LaravelImportEngine\Enums\ImportStatus;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Illuminate\Support\Facades\Storage;

function importFrom(int $daysAgo, ImportStatus $status, string $path): Import
{
    Storage::disk('local')->put($path, "Name,Email\n");

    $import = Import::query()->create([
        'type' => TestImportType::Contacts,
        'status' => $status,
        'file_name' => 'contacts.csv',
        'file_path' => $path,
        'disk' => 'local',
    ]);

    $import->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

    return $import;
}

it('prunes nothing when no retention is configured', function () {
    Storage::fake('local');

    importFrom(400, ImportStatus::Completed, 'imports/old.csv');

    $this->artisan('model:prune', ['--model' => [Import::class]])->assertSuccessful();

    expect(Import::query()->count())->toBe(1);
});

it('prunes the settled imports that are older than the retention, with their files', function () {
    Storage::fake('local');
    config()->set('import-engine.prune_after_days', 30);

    importFrom(31, ImportStatus::Completed, 'imports/completed.csv');
    importFrom(31, ImportStatus::Failed, 'imports/failed.csv');
    $processing = importFrom(31, ImportStatus::Processing, 'imports/processing.csv');
    $recent = importFrom(5, ImportStatus::Completed, 'imports/recent.csv');

    $this->artisan('model:prune', ['--model' => [Import::class]])->assertSuccessful();

    expect(Import::query()->pluck('id')->sort()->values()->all())->toBe([$processing->id, $recent->id])
        ->and(Storage::disk('local')->exists('imports/completed.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/failed.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/recent.csv'))->toBeTrue();
});
