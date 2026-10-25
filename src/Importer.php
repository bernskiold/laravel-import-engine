<?php

namespace Bernskiold\LaravelImportEngine;

use Bernskiold\LaravelImportEngine\Exceptions\ImportEngineException;
use Bernskiold\LaravelImportEngine\Exceptions\MissingColumnsException;
use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\Support\Column;
use Bernskiold\LaravelImportEngine\Support\ImportContext;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use Throwable;

/**
 * The part of an import that is the same for each type of record.
 *
 * A subclass gives the columns that it reads and what to do with one row.
 * This class does the other work: it reads the file, applies the column
 * map, guesses the map and catches a row that fails.
 *
 * The loop has three rules:
 *
 * - Each row must give one entry in the report. A row that is skipped,
 *   that conflicts or that fails also gives an entry. The loop records the
 *   entry for a row that fails. importRow() records the other entries.
 * - A row that throws an exception does not stop the run. It becomes a
 *   Failed entry, and the loop reads the next row.
 * - The importer does not decide to commit. It writes the data. The caller
 *   decides if the writes stay. Thus a preview is a real run in a
 *   transaction that is rolled back.
 *
 * For an import that does not work row by row, override handle().
 */
abstract class Importer
{
    private ?ImportContext $context = null;

    /**
     * @var array<string, string> The field for each column heading.
     */
    private array $fieldsByColumn = [];

    /**
     * The number of rows that the run has read.
     */
    private int $processed = 0;

    /**
     * The validation rules of the run, read one time for each run.
     *
     * @var array<string, mixed>|null
     */
    private ?array $runRules = null;

    /**
     * The columns that the importer can read, in the order to show them.
     *
     * @return array<int, Column>
     */
    abstract public function columns(): array;

    /**
     * The counts in the report that are not counts of rows.
     *
     * @return array<string, string> The tally key, and its label.
     */
    public function tallies(): array
    {
        return [];
    }

    /**
     * The database connections that the importer writes to.
     *
     * A preview starts a transaction on each connection and rolls back each
     * one. Null is the default connection.
     *
     * @return array<int, string|null>
     */
    public function connections(): array
    {
        return [null];
    }

    /**
     * The validation rules for each row, keyed by field.
     *
     * The engine validates each row before it gives the row to importRow().
     * A row that is not valid is a failed entry with the messages of the
     * validator. The values are the strings of ImportRow::toArray(), with
     * null for an empty cell or a placeholder.
     *
     *     return [
     *         'email' => ['required', 'email'],
     *         'rank' => ['nullable', 'integer', 'min:1'],
     *     ];
     *
     * The engine reads the rules one time for each run, so the rules can use
     * option().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Custom messages for the validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * The names of the fields in the validation messages.
     *
     * The default is the label of each column.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return $this->fields();
    }

    /**
     * True to write each row in its own transaction.
     *
     * Then a row that fails does not leave part of its writes. In a preview,
     * each row is a savepoint, so a row that fails does not stop the
     * transaction of the preview. Only the default handle() uses this.
     */
    public function transactionPerRow(): bool
    {
        return true;
    }

    /**
     * Read the file and do the import.
     *
     * @throws UnreadableFileException
     * @throws MissingColumnsException
     */
    final public function import(ImportContext $context): ImportReport
    {
        $map = $context->map !== [] ? $context->map : $this->guessMap($context->spreadsheet->headers());

        $missing = $this->missingRequiredFields($map);

        if ($missing !== []) {
            throw new MissingColumnsException($missing);
        }

        $this->context = $context;
        $this->fieldsByColumn = $this->fieldsByColumn($map);
        $this->processed = 0;
        $this->runRules = null;

        $report = new ImportReport;

        try {
            $this->handle($report);
        } finally {
            $this->saveProgress();

            $this->context = null;
            $this->fieldsByColumn = [];
            $this->runRules = null;
        }

        return $report;
    }

    /**
     * Do the import of the file at the path, without a record.
     *
     * Use this from a console command, a test or other code that has a file
     * on the local disk. The run is not in one transaction, so the rows that
     * it writes stay.
     *
     * @param  array<string, string|null>  $map
     * @param  array<string, mixed>  $options
     *
     * @throws UnreadableFileException
     * @throws MissingColumnsException
     */
    public function execute(string $path, array $map = [], array $options = [], ?int $limit = null): ImportReport
    {
        return $this->import(ImportContext::forFile($path, $map, $options, $limit));
    }

    /**
     * The field for each column, from the column headings.
     *
     * A field goes to the first column that agrees with it. If a file has
     * "Client" and "Client Name", the second column gets no field. A person
     * can then select the correct column.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null>
     */
    final public function guessMap(array $headers): array
    {
        $candidates = $this->candidatesByField();
        $map = [];
        $claimed = [];

        foreach ($headers as $header) {
            $normalised = self::normalise($header);

            $field = collect($candidates)
                ->reject(fn (array $spellings, string $field): bool => in_array($field, $claimed, true))
                ->search(fn (array $spellings): bool => in_array($normalised, $spellings, true));

            $map[$header] = is_string($field) ? $field : null;

            if (is_string($field)) {
                $claimed[] = $field;
            }
        }

        return $map;
    }

    /**
     * The required fields that the map does not supply.
     *
     * @param  array<string, string|null>  $map
     * @return array<int, string> The labels of the fields.
     */
    final public function missingRequiredFields(array $map): array
    {
        $mapped = array_filter(array_values($map));

        return collect($this->columns())
            ->filter(fn (Column $column): bool => $column->isRequired())
            ->reject(fn (Column $column): bool => in_array($column->key(), $mapped, true))
            ->map(fn (Column $column): string => $column->label())
            ->values()
            ->all();
    }

    /**
     * The fields, keyed by field, with their labels.
     *
     * @return array<string, string>
     */
    final public function fields(): array
    {
        return collect($this->columns())
            ->mapWithKeys(fn (Column $column): array => [$column->key() => $column->label()])
            ->all();
    }

    /**
     * The keys of the required fields.
     *
     * @return array<int, string>
     */
    final public function requiredFields(): array
    {
        return collect($this->columns())
            ->filter(fn (Column $column): bool => $column->isRequired())
            ->map(fn (Column $column): string => $column->key())
            ->values()
            ->all();
    }

    /**
     * The headings of a blank file that a person can complete.
     *
     * The headings come from columns(), so the template can not be different
     * from what the importer reads. The engine can guess the map from each
     * heading, so a completed template maps itself.
     *
     * There is no example row, because the engine would import it.
     *
     * @return array<int, string>
     */
    public function template(): array
    {
        return collect($this->columns())
            ->map(fn (Column $column): string => $column->getHeading())
            ->values()
            ->all();
    }

    /**
     * Do the import of the rows.
     *
     * The default reads each row and gives it to importRow(). Override this
     * method to read the file in a different way, for example to validate
     * all the rows first, or to write in batches. Use rows() to read the
     * mapped rows. An override must catch its own failures, and it does not
     * get a transaction for each row.
     */
    protected function handle(ImportReport $report): void
    {
        if ((new ReflectionMethod($this, 'importRow'))->getDeclaringClass()->getName() === self::class) {
            throw ImportEngineException::nothingToImport(static::class);
        }

        foreach ($this->rows() as $row) {
            /*
             * The row writes to its own report. If the row fails, the engine
             * discards that report, because the writes that it records were
             * rolled back.
             */
            $rowReport = new ImportReport;

            $this->advance();

            $errors = $this->validationErrors($row);

            if ($errors !== []) {
                $report->failed($row, $this->subject($row), $errors);

                continue;
            }

            try {
                $this->transactionPerRow()
                    ? $this->inTransactions(fn () => $this->importRow($row, $rowReport), $this->connections())
                    : $this->importRow($row, $rowReport);

                $report->merge($rowReport);
            } catch (Throwable $exception) {
                /*
                 * Record the message, and do not throw again. A person can
                 * correct the row, and the other rows stay imported.
                 */
                if (config('import-engine.report_row_failures', true)) {
                    report($exception);
                }

                $report->failed($row, $this->subject($row), [$exception->getMessage()]);
            }
        }
    }

    /**
     * Do what one row tells, and record what occurred in the report.
     */
    protected function importRow(ImportRow $row, ImportReport $report): void
    {
        throw ImportEngineException::nothingToImport(static::class);
    }

    /**
     * Count rows as read, for the progress of the run.
     *
     * The default handle() calls this for each row. An override of handle()
     * calls it when it reads rows. The engine writes the progress to the
     * record at an interval, see the "progress_interval" config, and at the
     * end of the run. A preview does not write progress.
     */
    final protected function advance(int $rows = 1): void
    {
        $this->processed += $rows;

        $interval = max(1, (int) config('import-engine.progress_interval', 50));

        if (intdiv($this->processed, $interval) !== intdiv($this->processed - $rows, $interval)) {
            $this->saveProgress();
        }
    }

    /**
     * The messages of the rules that the row does not obey.
     *
     * @return array<int, string>
     */
    final protected function validationErrors(ImportRow $row): array
    {
        $this->runRules ??= $this->rules();

        if ($this->runRules === []) {
            return [];
        }

        return Validator::make($row->toArray(), $this->runRules, $this->messages(), $this->validationAttributes())
            ->errors()
            ->all();
    }

    /**
     * Each row of the file, after the column map.
     *
     * The rows stop at the limit of the context.
     *
     * @return Generator<int, ImportRow>
     *
     * @throws UnreadableFileException
     */
    final protected function rows(): Generator
    {
        $context = $this->context();
        $read = 0;

        foreach ($context->spreadsheet->rows() as $row) {
            if ($context->limit !== null && $read >= $context->limit) {
                break;
            }

            $read++;

            yield new ImportRow(
                line: $row['line'],
                values: $this->applyMap($row['values']),
                original: $row['values'],
            );
        }
    }

    /**
     * The context of the run that is in progress.
     */
    final protected function context(): ImportContext
    {
        if ($this->context === null) {
            throw new LogicException('The importer is not running. Call import() or execute().');
        }

        return $this->context;
    }

    /**
     * One option of the run, with dot notation for nested values.
     */
    final protected function option(string $key, mixed $default = null): mixed
    {
        return $this->context()->option($key, $default);
    }

    /**
     * True when the run is a preview, in a transaction that is rolled back.
     *
     * Use this to stop side effects that a rollback does not undo, for
     * example an email or a request to an API.
     */
    final protected function previewing(): bool
    {
        return $this->context()->preview;
    }

    /**
     * The name of the row in the report.
     *
     * The default is the value of the first required field that has a value.
     * If there is none, it is the value of the first field that has a value.
     */
    protected function subject(ImportRow $row): ?string
    {
        $fields = collect($this->requiredFields())->merge(array_keys($this->fields()));

        foreach ($fields as $field) {
            if ($row->has($field)) {
                return $row->get($field);
            }
        }

        return null;
    }

    private function saveProgress(): void
    {
        $context = $this->context;

        if ($context === null || $context->preview || $context->import === null) {
            return;
        }

        $context->import->recordProgress($this->processed);
    }

    /**
     * Run the callback in a transaction on each of the connections.
     *
     * @param  array<int, string|null>  $connections
     */
    private function inTransactions(callable $callback, array $connections): mixed
    {
        if ($connections === []) {
            return $callback();
        }

        $connection = array_shift($connections);

        return DB::connection($connection)->transaction(fn (): mixed => $this->inTransactions($callback, $connections));
    }

    /**
     * @param  array<string, string|null>  $map
     * @return array<string, string> The column heading, and its field.
     */
    private function fieldsByColumn(array $map): array
    {
        $known = array_keys($this->fields());

        return collect($map)
            ->filter(fn (?string $field): bool => is_string($field) && in_array($field, $known, true))
            ->all();
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function applyMap(array $values): array
    {
        $mapped = [];

        foreach ($this->fieldsByColumn as $column => $field) {
            $mapped[$field] = $values[$column] ?? '';
        }

        return $mapped;
    }

    /**
     * The normalised spellings of each field: its key, its label and its
     * aliases.
     *
     * @return array<string, array<int, string>>
     */
    private function candidatesByField(): array
    {
        return collect($this->columns())
            ->mapWithKeys(fn (Column $column): array => [
                $column->key() => collect([$column->key(), $column->label(), $column->getHeading()])
                    ->merge($column->getAliases())
                    ->map(fn (string $spelling): string => self::normalise($spelling))
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * The letters and digits of a heading, in lowercase.
     *
     * "Client Name", "client_name" and "CLIENT-NAME" all become "clientname".
     */
    private static function normalise(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
    }
}
