<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Bernskiold\LaravelImportEngine\Importer;
use Bernskiold\LaravelImportEngine\Support\Column;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;
use RuntimeException;

/**
 * A mapped importer: it makes or updates a contact for each row.
 *
 * A row with the name "boom" throws an exception after it writes, so that a
 * test can see the rollback of one row.
 */
class ContactImporter extends Importer
{
    /**
     * The options that the last run received.
     *
     * @var array<string, mixed>
     */
    public static array $lastOptions = [];

    public static ?bool $lastPreview = null;

    public function columns(): array
    {
        return [
            Column::make('name', 'Name')->required(),
            Column::make('email', 'Email')->required()->aliases('e-mail address', 'mail'),
            Column::make('title', 'Job title')->aliases('position'),
        ];
    }

    public function tallies(): array
    {
        return ['titles_set' => 'Titles set'];
    }

    protected function importRow(ImportRow $row, ImportReport $report): void
    {
        static::$lastOptions = $this->context()->options;
        static::$lastPreview = $this->previewing();

        $contact = Contact::query()->firstOrNew(['email' => $row->get('email')]);
        $contact->fill(array_filter([
            'name' => $row->get('name'),
            'title' => $row->get('title'),
        ]));

        $created = ! $contact->exists;
        $changed = $contact->isDirty();
        $contact->save();

        if ($row->get('name') === 'boom') {
            $report->created($row, 'boom');

            throw new RuntimeException('This row exploded.');
        }

        if ($row->has('title') && $changed) {
            $report->tally('titles_set');
        }

        match (true) {
            $created => $report->created($row, $contact->name),
            $changed => $report->updated($row, $contact->name),
            default => $report->unchanged($row, $contact->name),
        };
    }
}
