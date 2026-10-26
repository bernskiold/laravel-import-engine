<?php

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Exceptions\ImportEngineException;
use Bernskiold\LaravelImportEngine\Exceptions\MissingColumnsException;
use Bernskiold\LaravelImportEngine\Exceptions\RowException;
use Bernskiold\LaravelImportEngine\Importer;
use Bernskiold\LaravelImportEngine\Support\Column;
use Bernskiold\LaravelImportEngine\Support\ImportContext;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\Contact;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;

it('reads each row through the map', function () {
    $report = (new ContactImporter)->execute(
        spreadsheet("Full name,Address,Notes\nSony,sony@example.com,ignore\n"),
        ['Full name' => 'name', 'Address' => 'email', 'Notes' => null],
    );

    expect($report->rows())->toBe(1)
        ->and($report->entries()->first()->subject)->toBe('Sony')
        ->and(Contact::query()->sole()->email)->toBe('sony@example.com');
});

it('guesses the map when it gets none', function () {
    $report = (new ContactImporter)->execute(spreadsheet("NAME,E-mail Address,Position\nSony,sony@example.com,CMO\n"));

    expect($report->countOf(ImportOutcome::Created))->toBe(1)
        ->and(Contact::query()->sole()->title)->toBe('CMO');
});

it('guesses the field from the key, the label and the aliases', function () {
    expect((new ContactImporter)->guessMap(['name', 'Mail', 'Job Title', 'Notes']))->toBe([
        'name' => 'name',
        'Mail' => 'email',
        'Job Title' => 'title',
        'Notes' => null,
    ]);
});

it('gives a field to the first column that agrees with it only', function () {
    expect((new ContactImporter)->guessMap(['Email', 'E-mail']))->toBe([
        'Email' => 'email',
        'E-mail' => null,
    ]);
});

it('tells which required fields the map does not supply', function () {
    expect((new ContactImporter)->missingRequiredFields(['Client' => 'name', 'Notes' => null]))->toBe(['Email']);
});

it('stops when a required column is missing', function () {
    (new ContactImporter)->execute(spreadsheet("Name,Notes\nSony,x\n"));
})->throws(MissingColumnsException::class, 'These required columns are missing: Email.');

it('records a row that fails and reads the next row', function () {
    Exceptions::fake();

    $report = (new ContactImporter)->execute(spreadsheet("Name,Email\nSony,sony@example.com\nboom,boom@example.com\nPepsi,pepsi@example.com\n"));

    expect($report->rows())->toBe(3)
        ->and($report->countOf(ImportOutcome::Created))->toBe(2)
        ->and($report->countOf(ImportOutcome::Failed))->toBe(1);

    $failed = $report->entries()->firstWhere('outcome', ImportOutcome::Failed);

    expect($failed->line)->toBe(3)
        ->and($failed->subject)->toBe('boom')
        ->and($failed->notes)->toBe(['The row could not be imported because of an error in the application.']);

    Exceptions::assertReported(RuntimeException::class);
});

it('shows the message of a row exception to the person, and does not report it', function () {
    Exceptions::fake();

    $importer = new class extends Importer
    {
        public function columns(): array
        {
            return [Column::make('name')->required()];
        }

        protected function importRow(ImportRow $row, ImportReport $report): void
        {
            match ($row->get('name')) {
                'future' => throw new RowException('The date is in the future.'),
                'invalid' => throw ValidationException::withMessages(['name' => ['The name is not valid.']]),
                default => $report->created($row, $row->get('name')),
            };
        }
    };

    $report = $importer->execute(spreadsheet("name,other\nfuture,1\ninvalid,2\nSony,3\n"));

    expect($report->entries()->pluck('notes')->all())->toBe([
        ['The date is in the future.'],
        ['The name is not valid.'],
        [],
    ]);

    Exceptions::assertNothingReported();
});

it('shows the message of each row exception when configured', function () {
    Exceptions::fake();
    config()->set('import-engine.show_exception_messages', true);

    $report = (new ContactImporter)->execute(spreadsheet("Name,Email\nboom,boom@example.com\n"));

    expect($report->entries()->sole()->notes)->toBe(['This row exploded.']);
});

it('rolls back the writes of a row that fails', function () {
    Exceptions::fake();

    (new ContactImporter)->execute(spreadsheet("Name,Email\nboom,boom@example.com\nSony,sony@example.com\n"));

    expect(Contact::query()->pluck('email')->all())->toBe(['sony@example.com']);
});

it('can keep row failures from the exception handler', function () {
    Exceptions::fake();
    config()->set('import-engine.report_row_failures', false);

    (new ContactImporter)->execute(spreadsheet("Name,Email\nboom,boom@example.com\n"));

    Exceptions::assertNothingReported();
});

it('reads only up to the limit', function () {
    $report = (new ContactImporter)->execute(spreadsheet("Name,Email\nA,a@example.com\nB,b@example.com\nC,c@example.com\n"), limit: 2);

    expect($report->rows())->toBe(2);
});

it('ignores a column mapped to a field that the importer does not have', function () {
    $report = (new ContactImporter)->execute(
        spreadsheet("Name,Email,Title\nSony,sony@example.com,CMO\n"),
        ['Name' => 'name', 'Email' => 'email', 'Title' => 'invented'],
    );

    expect($report->rows())->toBe(1)
        ->and(Contact::query()->sole()->title)->toBeNull();
});

it('gives the options and the preview flag to the importer', function () {
    (new ContactImporter)->execute(spreadsheet("Name,Email\nSony,sony@example.com\n"), options: ['survey' => 12]);

    expect(ContactImporter::$lastOptions)->toBe(['survey' => 12])
        ->and(ContactImporter::$lastPreview)->toBeFalse();
});

it('records the tallies', function () {
    $report = (new ContactImporter)->execute(spreadsheet("Name,Email,Title\nSony,sony@example.com,CMO\nPepsi,pepsi@example.com,\n"));

    expect($report->tallies())->toBe(['titles_set' => 1]);
});

it('makes a template from the columns that maps itself', function () {
    $importer = new ContactImporter;

    expect($importer->template())->toBe(['name', 'email', 'job_title'])
        ->and($importer->guessMap($importer->template()))->toBe([
            'name' => 'name',
            'email' => 'email',
            'job_title' => 'title',
        ]);
});

it('gives the fields and the required fields', function () {
    $importer = new ContactImporter;

    expect($importer->fields())->toBe(['name' => 'Name', 'email' => 'Email', 'title' => 'Job title'])
        ->and($importer->requiredFields())->toBe(['name', 'email']);
});

it('lets an importer read the file in its own way', function () {
    $importer = new class extends Importer
    {
        public function columns(): array
        {
            return [Column::make('name')->required()];
        }

        protected function handle(ImportReport $report): void
        {
            $names = collect($this->rows())->map(fn (ImportRow $row): ?string => $row->get('name'));

            $report->created(null, $names->implode(', '));
        }
    };

    $report = $importer->import(ImportContext::forFile(spreadsheet("name,other\nSony,1\nPepsi,2\n")));

    expect($report->rows())->toBe(1)
        ->and($report->entries()->first()->subject)->toBe('Sony, Pepsi');
});

it('stops an importer that does not import', function () {
    $importer = new class extends Importer
    {
        public function columns(): array
        {
            return [Column::make('name')];
        }
    };

    $importer->execute(spreadsheet("name,other\nSony,1\n"));
})->throws(ImportEngineException::class);

it('can write each row without its own transaction', function () {
    Exceptions::fake();

    $importer = new class extends ContactImporter
    {
        public function transactionPerRow(): bool
        {
            return false;
        }
    };

    $importer->execute(spreadsheet("Name,Email\nboom,boom@example.com\n"));

    expect(Contact::query()->count())->toBe(1);
});
