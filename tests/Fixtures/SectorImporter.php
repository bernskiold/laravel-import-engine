<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Bernskiold\LaravelImportEngine\SimpleImporter;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;

/**
 * A simple importer with fixed headings.
 */
class SectorImporter extends SimpleImporter
{
    protected array $requiredHeadings = ['name'];

    protected array $optionalHeadings = ['is_active'];

    protected function importRow(ImportRow $row, ImportReport $report): void
    {
        $sector = Sector::query()->firstOrNew(['name' => $row->get('name')]);
        $sector->is_active = $row->boolean('is_active', default: true);

        $created = ! $sector->exists;
        $changed = $sector->isDirty();
        $sector->save();

        match (true) {
            $created => $report->created($row, $sector->name),
            $changed => $report->updated($row, $sector->name),
            default => $report->unchanged($row, $sector->name),
        };
    }
}
