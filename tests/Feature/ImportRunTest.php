<?php

use Bernskiold\LaravelImportEngine\Actions\PreviewImport;
use Bernskiold\LaravelImportEngine\Actions\RunImport;
use Bernskiold\LaravelImportEngine\Actions\StartImport;
use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Enums\ImportStatus;
use Bernskiold\LaravelImportEngine\Events\ImportCompleted;
use Bernskiold\LaravelImportEngine\Events\ImportFailed;
use Bernskiold\LaravelImportEngine\Events\ImportStarted;
use Bernskiold\LaravelImportEngine\Exceptions\MissingColumnsException;
use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\Jobs\RunImportJob;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\Contact;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\TestImportType;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const CONTACTS_FILE = "Name,Email\nSony,sony@example.com\nPepsi,pepsi@example.com\n";

/**
 * Store the contents on the fake disk and make a pending record for them.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingImport(string $contents = CONTACTS_FILE, array $attributes = []): Import
{
    Storage::disk('local')->put('imports/contacts.csv', $contents);

    return Import::query()->create([
        'type' => TestImportType::Contacts,
        'file_name' => 'contacts.csv',
        'file_path' => 'imports/contacts.csv',
        'disk' => 'local',
        ...$attributes,
    ]);
}

it('stores the file, makes the record and sends the run to the queue', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::query()->create(['name' => 'Ada']);
    $this->actingAs($user);

    $import = app(StartImport::class)->execute(
        TestImportType::Contacts,
        uploadedSpreadsheet(CONTACTS_FILE),
        ['Name' => 'name', 'Email' => 'email'],
        ['survey' => 12],
    );

    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->type)->toBe(TestImportType::Contacts)
        ->and($import->file_name)->toBe('contacts.csv')
        ->and($import->column_map)->toBe(['Name' => 'name', 'Email' => 'email'])
        ->and($import->options)->toBe(['survey' => 12])
        ->and($import->user_id)->toBe($user->id)
        ->and($import->uuid)->toBeString()
        ->and($import->file_path)->toStartWith('imports/')
        ->and(Storage::disk('local')->exists($import->file_path))->toBeTrue();

    Queue::assertPushed(RunImportJob::class, fn (RunImportJob $job): bool => $job->import->is($import));
});

it('starts an import from a local file, for a user and with more attributes', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::query()->create(['name' => 'Ada']);

    $import = app(StartImport::class)->execute(
        TestImportType::Contacts,
        spreadsheet(CONTACTS_FILE),
        user: $user,
        attributes: ['failure_reason' => 'kept'],
        fileName: 'export.csv',
    );

    expect($import->file_name)->toBe('export.csv')
        ->and($import->user_id)->toBe($user->id)
        ->and($import->failure_reason)->toBe('kept')
        ->and(Storage::disk('local')->exists($import->file_path))->toBeTrue();
});

it('uses the configured disk and directory', function () {
    Queue::fake();
    Storage::fake('uploads');
    config()->set('import-engine.disk', 'uploads');
    config()->set('import-engine.directory', 'incoming/');

    $import = app(StartImport::class)->execute(TestImportType::Contacts, uploadedSpreadsheet(CONTACTS_FILE));

    expect($import->disk)->toBe('uploads')
        ->and($import->file_path)->toStartWith('incoming/')
        ->and(Storage::disk('uploads')->exists($import->file_path))->toBeTrue();
});

it('runs the import and writes the report to the record', function () {
    Storage::fake('local');
    Event::fake([ImportStarted::class, ImportCompleted::class, ImportFailed::class]);

    $import = pendingImport();

    $report = app(RunImport::class)->execute($import);

    $import->refresh();

    expect($report->rows())->toBe(2)
        ->and($import->status)->toBe(ImportStatus::Completed)
        ->and($import->started_at)->not->toBeNull()
        ->and($import->completed_at)->not->toBeNull()
        ->and($import->importReport()->countOf(ImportOutcome::Created))->toBe(2)
        ->and(Contact::query()->count())->toBe(2)
        ->and($import->hasFile())->toBeTrue();

    Event::assertDispatched(ImportStarted::class);
    Event::assertDispatched(ImportCompleted::class, fn (ImportCompleted $event): bool => $event->import->is($import));
    Event::assertNotDispatched(ImportFailed::class);
});

it('gives the map, the options and the record to the importer', function () {
    Storage::fake('local');

    $import = pendingImport("Who,Address\nSony,sony@example.com\n", [
        'column_map' => ['Who' => 'name', 'Address' => 'email'],
        'options' => ['survey' => 12],
    ]);

    app(RunImport::class)->execute($import);

    expect(Contact::query()->sole()->name)->toBe('Sony')
        ->and(ContactImporter::$lastOptions)->toBe(['survey' => 12]);
});

it('marks the run as failed when the file is not there', function () {
    Storage::fake('local');
    Event::fake([ImportFailed::class]);

    $import = Import::query()->create([
        'type' => TestImportType::Contacts,
        'file_name' => 'missing.csv',
        'file_path' => 'imports/missing.csv',
        'disk' => 'local',
    ]);

    expect(fn () => app(RunImport::class)->execute($import))->toThrow(UnreadableFileException::class);

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failed_at)->not->toBeNull()
        ->and($import->failure_reason)->toBe('The uploaded file could not be found. Upload it again.');

    Event::assertDispatched(ImportFailed::class);
});

it('marks the run as failed when a required column is missing', function () {
    Storage::fake('local');

    $import = pendingImport("Name,Notes\nSony,x\n");

    expect(fn () => app(RunImport::class)->execute($import))->toThrow(MissingColumnsException::class);

    expect($import->refresh()->failure_reason)->toBe('These required columns are missing: Email.');
});

it('deletes the file after the run when configured', function (string $contents) {
    Storage::fake('local');
    config()->set('import-engine.delete_files_after_run', true);

    $import = pendingImport($contents);

    rescue(fn () => app(RunImport::class)->execute($import), report: false);

    expect($import->refresh()->isSettled())->toBeTrue()
        ->and(Storage::disk('local')->exists('imports/contacts.csv'))->toBeFalse()
        ->and($import->file_path)->toBe('imports/contacts.csv');
})->with([
    'completed' => [CONTACTS_FILE],
    'failed' => ["Name,Notes\nSony,x\n"],
]);

it('previews the import and rolls it back', function () {
    $report = app(PreviewImport::class)->execute(TestImportType::Contacts, spreadsheet(CONTACTS_FILE));

    expect($report->rows())->toBe(2)
        ->and($report->countOf(ImportOutcome::Created))->toBe(2)
        ->and(ContactImporter::$lastPreview)->toBeTrue()
        ->and(Contact::query()->count())->toBe(0);
});

it('previews with an importer, a map and a limit', function () {
    $report = app(PreviewImport::class)->execute(
        new ContactImporter,
        spreadsheet("Who,Address\nA,a@example.com\nB,b@example.com\nC,c@example.com\n"),
        ['Who' => 'name', 'Address' => 'email'],
        limit: 2,
    );

    expect($report->rows())->toBe(2)
        ->and(Contact::query()->count())->toBe(0);
});

it('rolls the preview back when it stops halfway', function () {
    try {
        app(PreviewImport::class)->execute(TestImportType::Contacts, spreadsheet("Name,Notes\nSony,x\n"));
    } catch (MissingColumnsException) {
        //
    }

    expect(Contact::query()->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('keeps a row that fails in a preview from the other rows', function () {
    Exceptions::fake();

    $report = app(PreviewImport::class)->execute(
        TestImportType::Contacts,
        spreadsheet("Name,Email\nboom,boom@example.com\nSony,sony@example.com\n"),
    );

    expect($report->countOf(ImportOutcome::Failed))->toBe(1)
        ->and($report->countOf(ImportOutcome::Created))->toBe(1)
        ->and(Contact::query()->count())->toBe(0);
});

it('runs the import as the person who started it', function () {
    Storage::fake('local');

    $user = User::query()->create(['name' => 'Ada']);
    $import = pendingImport(attributes: ['user_id' => $user->id]);

    (new RunImportJob($import))->handle(app(RunImport::class));

    expect(Contact::query()->pluck('created_by_id')->unique()->all())->toBe([$user->id])
        ->and(Auth::hasUser())->toBeFalse();
});

it('gives back the previous user after the run', function () {
    Storage::fake('local');

    $owner = User::query()->create(['name' => 'Ada']);
    $other = User::query()->create(['name' => 'Grace']);
    Auth::setUser($other);

    (new RunImportJob(pendingImport(attributes: ['user_id' => $owner->id])))->handle(app(RunImport::class));

    expect(Auth::id())->toBe($other->id);
});

it('does not throw for a file that is incorrect', function () {
    Storage::fake('local');

    $import = pendingImport("Name,Notes\nSony,x\n");

    (new RunImportJob($import))->handle(app(RunImport::class));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed);
});

it('runs on the configured queue', function () {
    config()->set('import-engine.queue', ['connection' => 'redis', 'queue' => 'imports', 'timeout' => 60]);

    $job = new RunImportJob(new Import);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('imports')
        ->and($job->timeout)->toBe(60)
        ->and($job->tries)->toBe(1);
});

it('marks the record as failed when the job dies', function () {
    Storage::fake('local');
    Event::fake([ImportFailed::class]);

    $import = pendingImport();
    $import->start();

    (new RunImportJob($import))->failed(new RuntimeException('Timed out.'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toBe('Timed out.');

    Event::assertDispatched(ImportFailed::class);
});

it('does not change a settled record when the job dies', function () {
    Storage::fake('local');

    $import = pendingImport();
    $import->fail('The first reason.');

    (new RunImportJob($import))->failed(new RuntimeException('Timed out.'));

    expect($import->refresh()->failure_reason)->toBe('The first reason.');
});

it('tags the job', function () {
    Storage::fake('local');

    $import = pendingImport();

    expect((new RunImportJob($import))->tags())->toBe(['import', 'import:'.$import->id, 'import-type:contacts']);
});
