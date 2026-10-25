<?php

namespace Bernskiold\LaravelImportEngine;

use Bernskiold\LaravelImportEngine\Support\Column;

/**
 * An importer for a file with fixed column headings.
 *
 * List the headings in two properties. The engine maps each heading of the
 * file to the field with the same name, without case and punctuation. You
 * do not need a column map, but the importer accepts one.
 *
 *     class ImportSectors extends SimpleImporter
 *     {
 *         protected array $requiredHeadings = ['name'];
 *
 *         protected array $optionalHeadings = ['description'];
 *
 *         protected function importRow(ImportRow $row, ImportReport $report): void
 *         {
 *             $sector = Sector::query()->firstOrCreate(['name' => $row->get('name')]);
 *
 *             $sector->wasRecentlyCreated
 *                 ? $report->created($row, $sector->name)
 *                 : $report->unchanged($row, $sector->name);
 *         }
 *     }
 *
 *     app(ImportSectors::class)->execute($path);
 *
 * To give a label or a description to a column, override columns().
 */
abstract class SimpleImporter extends Importer
{
    /**
     * The headings that the file must have.
     *
     * @var array<int, string>
     */
    protected array $requiredHeadings = [];

    /**
     * The headings that the file can have.
     *
     * @var array<int, string>
     */
    protected array $optionalHeadings = [];

    /**
     * @return array<int, Column>
     */
    public function columns(): array
    {
        return collect($this->requiredHeadings)
            ->map(fn (string $heading): Column => Column::make($heading)->heading($heading)->required())
            ->merge(collect($this->optionalHeadings)
                ->map(fn (string $heading): Column => Column::make($heading)->heading($heading)))
            ->values()
            ->all();
    }
}
