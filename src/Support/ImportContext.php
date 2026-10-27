<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Bernskiold\LaravelImportEngine\Models\Import;

/**
 * The input of one import run.
 *
 * - The spreadsheet is the open file.
 * - The map gives the field for each column heading of the file. A null
 *   field means that the column is not imported. An empty map tells the
 *   importer to guess the map from the headings.
 * - The options are the values that the importer needs in addition to the
 *   file, for example the ID of a survey.
 * - The limit stops the run after this number of rows.
 * - Preview is true when the run is in a transaction that is rolled back.
 * - Import is the record of the run, when the run has one.
 */
final readonly class ImportContext
{
    /**
     * @param  array<string, string|null>  $map
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public Spreadsheet $spreadsheet,
        public array $map = [],
        public array $options = [],
        public ?int $limit = null,
        public bool $preview = false,
        public ?Import $import = null,
    ) {}

    /**
     * Open the file at the path and make a context for it.
     *
     * @param  array<string, string|null>  $map
     * @param  array<string, mixed>  $options
     */
    public static function forFile(string $path, array $map = [], array $options = [], ?int $limit = null, bool $preview = false): self
    {
        return new self(Spreadsheet::open($path), $map, $options, $limit, $preview);
    }

    /**
     * One option, with dot notation for nested values.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
