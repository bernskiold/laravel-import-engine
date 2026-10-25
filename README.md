# Laravel Import Engine

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bernskiold/laravel-import-engine.svg?style=flat-square)](https://packagist.org/packages/bernskiold/laravel-import-engine)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-import-engine/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bernskiold/laravel-import-engine/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-import-engine/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/bernskiold/laravel-import-engine/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

This package imports CSV and Excel files into a Laravel application. You write one class for each type of import. The class tells which columns it reads and what to do with one row. The engine does the other work:

- It reads the file with tolerance. It finds the headings under a title row, it reads semicolon files, and it keeps the line numbers of the file.
- It guesses the column map from the headings, and it accepts a map from a person.
- It makes a preview: it runs the import in a transaction, and then it rolls the transaction back.
- It runs the import on the queue, as the person who started it.
- It keeps a report with one entry for each row: created, updated, unchanged, conflicted, ignored or failed.

```php
use Bernskiold\LaravelImportEngine\Actions\StartImport;

$import = app(StartImport::class)->execute(ImportType::Contacts, $request->file('file'));

// Later, on the queue:
$import->refresh()->importReport()->countOf(ImportOutcome::Created); // 412
```

## Contents

- [Installation](#installation)
- [The import types](#the-import-types)
- [Write an importer](#write-an-importer)
- [Write a simple importer](#write-a-simple-importer)
- [Read the values of a row](#read-the-values-of-a-row)
- [Record what occurred](#record-what-occurred)
- [Run an import](#run-an-import)
- [Make a preview](#make-a-preview)
- [Build a mapping screen](#build-a-mapping-screen)
- [Give a template to download](#give-a-template-to-download)
- [Events](#events)
- [Extend the import model](#extend-the-import-model)
- [Configuration](#configuration)
- [Testing](#testing)

## Installation

Install the package with Composer:

```bash
composer require bernskiold/laravel-import-engine
```

Publish the migration and the configuration file. Then run the migration:

```bash
php artisan vendor:publish --tag=import-engine-migrations
php artisan vendor:publish --tag=import-engine-config
php artisan migrate
```

The migration makes the `imports` table. Each row of the table is one run of one importer on one file.

The package requires PHP 8.3 or higher, Laravel 11, 12 or 13, and `spatie/simple-excel`. It supports openspout 4 and 5.

## The import types

Make a string-backed enum that lists the imports of your application. Put the `ImportType` contract on the enum. Then set the enum in `config/import-engine.php`.

```php
use Bernskiold\LaravelImportEngine\Contracts\ImportType as ImportTypeContract;
use Bernskiold\LaravelImportEngine\Importer;

enum ImportType: string implements ImportTypeContract
{
    case Contacts = 'contacts';
    case Sectors = 'sectors';

    public function label(): string
    {
        return match ($this) {
            self::Contacts => __('Contacts'),
            self::Sectors => __('Sectors'),
        };
    }

    public function importer(): Importer
    {
        return app(match ($this) {
            self::Contacts => ContactImporter::class,
            self::Sectors => SectorImporter::class,
        });
    }
}
```

```php
// config/import-engine.php
'types' => App\Domain\Imports\Enums\ImportType::class,
```

The engine stores the value of the case in the `type` column. Do not change the values after you use them.

Resolve each importer from the container. Then the importer can get its dependencies in its constructor.

## Write an importer

Extend `Importer`. Give the columns in `columns()`, and write `importRow()`.

```php
use Bernskiold\LaravelImportEngine\Importer;
use Bernskiold\LaravelImportEngine\Support\Column;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;

class ContactImporter extends Importer
{
    public function columns(): array
    {
        return [
            Column::make('client', __('Client'))->required()->aliases('client name', 'account'),
            Column::make('email', __('Email'))->required()->aliases('email address', 'e-mail'),
            Column::make('name', __('Name')),
            Column::make('title', __('Job title'))->aliases('position', 'role')
                ->description(__('The title of the person at the client.')),
        ];
    }

    protected function importRow(ImportRow $row, ImportReport $report): void
    {
        $client = Client::query()->firstWhere('name', $row->get('client'));

        if ($client === null) {
            $report->conflicted($row, $row->get('email'), [__('No client has this name.')]);

            return;
        }

        $contact = $client->contacts()->firstOrNew(['email' => $row->get('email')]);
        $contact->fill(array_filter(['name' => $row->get('name'), 'title' => $row->get('title')]));

        $created = ! $contact->exists;
        $changed = $contact->isDirty();
        $contact->save();

        match (true) {
            $created => $report->created($row, $contact->email),
            $changed => $report->updated($row, $contact->email),
            default => $report->unchanged($row, $contact->email),
        };
    }
}
```

Obey these rules:

- Record one entry for each row. Use `unchanged()` when the record already had the values of the row. Then a second run of the same file shows that nothing changed.
- If `importRow()` throws an exception, the engine records the row as failed and reads the next row. The engine sends the exception to the exception handler, and it puts the message in the report.
- Each row is in its own database transaction. If a row fails, the engine rolls back the writes of that row only. To stop this, return `false` from `transactionPerRow()`.
- If the importer writes to a database connection that is not the default, return the connections from `connections()`. The transactions and the preview then use these connections.

### A column

`Column::make($key, $label)` makes a column. The key is the name of the field in each row. The label is the name that a person sees.

| Method | Use |
| --- | --- |
| `required()` | The run stops when the file, or the map, does not supply the column. |
| `aliases(...)` | Other headings that mean this field. The engine compares headings without case, spaces and punctuation, so give only the headings that use different words. |
| `description($text)` | A description for a screen that tells the person what the file must contain. |
| `heading($text)` | The heading in a blank template. The default is the label in snake case. |

### An import that does not work row by row

Override `handle()` when the import must read all the rows first, or write in batches. Use `rows()` to read the mapped rows. An override must catch its own failures. It does not get a transaction for each row.

```php
protected function handle(ImportReport $report): void
{
    $rows = collect($this->rows());

    // Validate all the rows, then write them in one transaction.
}
```

### Options

An import can need values in addition to the file, for example the ID of a survey. Give them as options when you start the import. The importer reads them with `option()`:

```php
$surveyId = $this->option('survey_id');
```

## Write a simple importer

For a file with fixed headings, extend `SimpleImporter`. List the headings in two properties. You do not need a column map, but the importer accepts one.

```php
use Bernskiold\LaravelImportEngine\SimpleImporter;

class SectorImporter extends SimpleImporter
{
    protected array $requiredHeadings = ['name'];

    protected array $optionalHeadings = ['is_active'];

    protected function importRow(ImportRow $row, ImportReport $report): void
    {
        $sector = Sector::query()->firstOrNew(['name' => $row->get('name')]);
        $sector->is_active = $row->boolean('is_active', default: true);

        $created = ! $sector->exists;
        $sector->save();

        $created ? $report->created($row, $sector->name) : $report->updated($row, $sector->name);
    }
}
```

Run it directly from a console command or a test:

```php
$report = app(SectorImporter::class)->execute($path);
```

A simple importer is an importer. It can go in the import types enum, and it can have a preview and a queued run. To add labels or descriptions, override `columns()`.

## Read the values of a row

`ImportRow` gives tolerant access to the values. Each value is a trimmed string.

| Method | Result |
| --- | --- |
| `get('field')` | The value, or `null`. A placeholder such as `-`, `n/a`, `TBC` or `#N/A` is `null`. |
| `has('field')` | `true` when `get()` is not `null`. |
| `values('field')` | A list. Commas, semicolons, pipes, slashes and line breaks separate the items. |
| `lines('field')` | A list with one item on each line. Use it for names that contain commas. |
| `integer('field')` | A whole number, or `null`. `#4`, `4.` and `4.0` are `4`. `4.5` and `top 10` are `null`. |
| `number('field')` | A number, or `null`. |
| `boolean('field', default: null)` | `yes`, `y`, `true`, `1` and `x` are `true`. `no`, `n`, `false` and `0` are `false`. Other values give the default. |
| `all()` | All the mapped values, without the placeholders. |
| `original()` | The row as it is in the file, keyed by the headings of the file. |
| `line` | The line number in the file. The headings are on line one, or lower when the file has a title row. |

## Record what occurred

Each entry has a row, an outcome, a subject, notes and warnings:

- The row is the line number in the file. Then a person can find the row in the spreadsheet.
- The subject is the name of the row, for example a client name or an email address.
- The notes tell what the importer did.
- The warnings tell about problems that did not stop the row.

```php
$report->created($row, $subject, notes: [...], warnings: [...]);
$report->updated($row, $subject);
$report->unchanged($row, $subject);
$report->conflicted($row, $subject, [__('The code belongs to a different client.')]);
$report->skipped($row, $subject, [__('There is no email in this row.')]);
$report->failed($row, $subject, [__('The date is not valid.')]);
```

Each outcome has a severity:

| Outcome | Severity |
| --- | --- |
| Created, Updated, Unchanged | Success |
| Conflicted, Skipped | Warning |
| Failed | Error |

Conflicted is not a failure. It means that the file does not agree with the data that you have, and the importer did not change the data.

Use tallies for counts that are not rows. Give their labels in `tallies()` on the importer:

```php
$report->tally('links_made');
```

Read the report:

```php
$report->rows();                                  // The number of entries.
$report->countOf(ImportOutcome::Created);
$report->countOfSeverity(ImportSeverity::Warning);
$report->needingAttention();                      // The entries that are not a success.
$report->countWithWarnings();
$report->changedNothing();                        // True when all entries are unchanged.
$report->entries();                               // A collection of ImportEntry.
$report->tallies();
```

## Run an import

`StartImport` keeps the file on a disk, makes the record and sends the run to the queue:

```php
use Bernskiold\LaravelImportEngine\Actions\StartImport;

$import = app(StartImport::class)->execute(
    type: ImportType::Contacts,
    file: $request->file('file'),          // An upload, or the path of a local file.
    map: ['Client Name' => 'client', 'E-mail' => 'email', 'Notes' => null],
    options: ['survey_id' => $survey->id],
    user: $request->user(),                // The default is the authenticated user.
);
```

An empty map tells the engine to guess the map from the headings.

The record is saved before the job is sent. The job runs after the database transaction commits. The job:

1. Sets the status to processing.
2. Signs in the person who started the import, so that activity logs and "created by" columns get the correct user. The previous user comes back after the run.
3. Reads the file and writes the report to the record.
4. Sets the status to completed, or to failed with a reason.

The job does not try again. If the file is incorrect, for example if a required column is missing, the job fails but it does not send the exception to the exception handler.

To run an import without a queue, use `RunImport`:

```php
app(RunImport::class)->execute($import);
```

Read a record:

```php
$import->status;               // ImportStatus::Pending, Processing, Completed or Failed.
$import->isSettled();          // True when completed or failed. Stop to poll when this is true.
$import->importReport();       // An ImportReport. It is empty when there is no report.
$import->failure_reason;
$import->user;
```

## Make a preview

`PreviewImport` runs the import in a transaction, and then rolls the transaction back. Thus the preview shows what the run will do, not a prediction.

```php
use Bernskiold\LaravelImportEngine\Actions\PreviewImport;

$report = app(PreviewImport::class)->execute(ImportType::Contacts, $path, $map, limit: 500);
```

The rollback does not undo side effects outside the database, for example an email or a queued job. In the importer, call `previewing()` to stop them:

```php
if (! $this->previewing()) {
    Mail::to($contact)->send(new Welcome($contact));
}
```

## Build a mapping screen

The importer gives the data that a mapping screen needs:

```php
$importer = ImportType::Contacts->importer();
$spreadsheet = Spreadsheet::open($path);

$spreadsheet->headers();                         // ['Client Name', 'E-mail', 'Notes']
$spreadsheet->preview(5);                        // The first five rows.
$spreadsheet->count();                           // The number of rows.

$importer->columns();                            // The columns, with labels and descriptions.
$importer->guessMap($spreadsheet->headers());    // ['Client Name' => 'client', 'E-mail' => 'email', 'Notes' => null]
$importer->missingRequiredFields($map);          // ['Email'] when no column gives the email.
```

The engine reads `.csv`, `.txt` (as CSV) and `.xlsx` files. It does not read `.ods` files, because it can not keep the line numbers of an ODS file. Validate uploads with `mimes:csv,txt,xlsx`.

## Give a template to download

`Workbook::download()` makes an Excel file. Use it for a blank template, or for an export that a person completes and imports again:

```php
use Bernskiold\LaravelImportEngine\Support\Workbook;

return Workbook::download('contacts-template.xlsx', $importer->template());

return Workbook::download('contacts.xlsx', $importer->template(), $rows);
```

The headings come from `columns()`, so a completed template maps itself. The workbook writes text as text. It does not write a value that starts with `=` as a formula.

## Events

| Event | When |
| --- | --- |
| `ImportStarted` | The queue started to read the file. |
| `ImportCompleted` | The run read the file to the end. The report is on the record. |
| `ImportFailed` | The run stopped. The reason is in `failure_reason`. |

Each event has the record in `$event->import`. Rows that fail do not cause `ImportFailed`. They are entries in the report of a completed run.

```php
Event::listen(ImportCompleted::class, function (ImportCompleted $event) {
    $event->import->user?->notify(new ImportFinished($event->import));
});
```

## Extend the import model

Extend the model to add columns, relations or scopes. Then set your class in the configuration:

```php
class Import extends \Bernskiold\LaravelImportEngine\Models\Import
{
    public function url(): string
    {
        return route('imports.show', $this);
    }
}
```

```php
// config/import-engine.php
'model' => App\Models\Import::class,
```

To set your own columns when an import starts, give them as attributes:

```php
app(StartImport::class)->execute(ImportType::Contacts, $file, attributes: ['team_id' => $team->id]);
```

The model deletes the file when you delete the record. The route key is the `uuid` column.

## Configuration

| Key | Default | Use |
| --- | --- | --- |
| `types` | `null` | The enum of the import types. |
| `model` | `Import::class` | The import model. |
| `table` | `imports` | The table of the import model. |
| `user_model` | `null` | The user model. The default is the model of the default auth provider. |
| `disk` | `null` | The disk for uploaded files. The default is the default filesystem disk. |
| `directory` | `imports` | The directory on the disk. |
| `delete_files_after_run` | `false` | Delete the file when the run stops. Set this to `true` for files with personal data. |
| `report_row_failures` | `true` | Send the exception of a failed row to the exception handler. |
| `queue.connection` | `null` | The queue connection of the job. |
| `queue.queue` | `null` | The queue of the job. |
| `queue.timeout` | `900` | The maximum time of one run, in seconds. |

## Testing

```bash
composer test
composer phpstan
composer format
```

## Changelog

Refer to [CHANGELOG](CHANGELOG.md) for the changes.

## Contributing

Refer to [CONTRIBUTING](CONTRIBUTING.md) for the procedure.

## Security

Refer to [our security policy](SECURITY.md) for the procedure to report a vulnerability.

## Credits

- [Bernskiold](https://github.com/bernskiold)
- [Erik Bernskiöld](https://github.com/ErikBernskiold)

## License

The MIT License (MIT). Refer to the [License File](LICENSE.md) for more information.
