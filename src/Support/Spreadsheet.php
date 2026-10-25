<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use Throwable;

/**
 * A CSV or XLSX file, read with tolerance.
 *
 * Files come from people, and people export what they have: a title row above
 * the table, a column of notes, blank lines at the end. This class does not
 * validate a schema. It finds the headings, gives back text, and lets the
 * importer decide what a value means.
 *
 * It makes three decisions:
 *
 * - The headings are on the first row that has more than one filled cell.
 *   A title row has one filled cell, so the engine does not use it. If no
 *   row has more than one filled cell, the file has one column, and the
 *   first filled row is the heading.
 * - Each cell is a trimmed string. Excel keeps a number such as 1234 as the
 *   float 1234.0, and a date as a DateTime. Both become the text that the
 *   person typed.
 * - This class, not the reader, removes the empty rows. The reader must keep
 *   them, so that each row number is the line number in the file.
 *
 * The extension selects the reader. The engine reads .csv, .txt (as CSV) and
 * .xlsx files. It does not read .ods files, see SpreadsheetReader.
 */
final class Spreadsheet
{
    /**
     * The minimum number of filled cells on the heading row.
     *
     * One cell is not sufficient, because a title row has one cell.
     */
    private const HEADER_MINIMUM_CELLS = 2;

    /**
     * @var array<int, string>
     */
    private array $headers = [];

    /**
     * The index of the heading row, from zero.
     *
     * The reader keeps every row, so this is the line number less one.
     */
    private int $headerIndex = 0;

    private function __construct(
        private readonly string $path,
    ) {}

    /**
     * @throws UnreadableFileException
     */
    public static function open(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw UnreadableFileException::missing($path);
        }

        $spreadsheet = new self($path);
        $spreadsheet->readHeaders();

        return $spreadsheet;
    }

    /**
     * The path of the file on the local filesystem.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The column headings, in the order of the file.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Each row below the headings, with the values keyed by heading.
     *
     * The generator reads one row at a time, so a large file does not fill
     * the memory.
     *
     * @return Generator<int, array{line: int, values: array<string, string>}>
     *
     * @throws UnreadableFileException
     */
    public function rows(): Generator
    {
        /*
         * Use the headings that this class made, not the headings of the file.
         * They have names for blank and duplicate columns.
         *
         * Do not call getHeaders() on this reader. simple-excel keeps the
         * headings in a cache, and then getRows() skips only one row from the
         * top. On a file with a title row, it gives back the headings as data.
         */
        $reader = $this->reader()
            ->headerOnRow($this->headerIndex)
            ->useHeaders($this->headers);

        try {
            foreach ($reader->getRows() as $index => $values) {
                $cells = array_map(fn (mixed $value): string => self::stringify($value), $values);

                if ($this->isBlank($cells)) {
                    continue;
                }

                /*
                 * The line of the headings, plus one for the headings row,
                 * plus one because lines start at one and indexes at zero.
                 */
                yield ['line' => $this->headerIndex + $index + 2, 'values' => $cells];
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * The first rows of the file, to show a person what the import reads.
     *
     * @return array<int, array{line: int, values: array<string, string>}>
     *
     * @throws UnreadableFileException
     */
    public function preview(int $limit = 5): array
    {
        $rows = [];

        foreach ($this->rows() as $row) {
            $rows[] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @throws UnreadableFileException
     */
    public function count(): int
    {
        return iterator_count($this->rows());
    }

    /**
     * Find the heading row and keep its index.
     *
     * The reader reads the file without headings, so that each row comes back
     * in position. Then this method examines each row.
     *
     * @throws UnreadableFileException
     */
    private function readHeaders(): void
    {
        $reader = $this->reader()->noHeaderRow();

        /*
         * The first row with one filled cell, in case the file has only one
         * column. Then each row has one cell, and that row is the heading.
         */
        $firstFilled = null;

        try {
            foreach ($reader->getRows() as $index => $values) {
                $cells = array_map(fn (mixed $value): string => self::stringify($value), array_values($values));
                $filled = array_filter($cells, fn (string $cell): bool => $cell !== '');

                if (count($filled) < self::HEADER_MINIMUM_CELLS) {
                    if ($filled !== [] && $firstFilled === null) {
                        $firstFilled = [$index, $cells];
                    }

                    continue;
                }

                $this->headerIndex = $index;
                $this->headers = $this->nameHeaders($cells);

                break;
            }

            if ($this->headers === [] && $firstFilled !== null) {
                [$this->headerIndex, $cells] = $firstFilled;

                $lastFilled = (int) array_key_last(array_filter($cells, fn (string $cell): bool => $cell !== ''));

                $this->headers = $this->nameHeaders(array_slice($cells, 0, $lastFilled + 1));
            }
        } catch (UnreadableFileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableFileException::unparseable($this->path, $exception);
        } finally {
            $reader->close();
        }

        if ($this->headers === []) {
            throw UnreadableFileException::withoutHeaders($this->path);
        }
    }

    /**
     * Give each column a name, and a unique name.
     *
     * A blank column keeps its position and gets the name "Column N".
     * If the engine removed it, each value after it would move one column to
     * the left. A duplicate name gets a number, for example "Notes (2)".
     * Without the number, the second column would replace the first.
     *
     * @param  array<int, string>  $cells
     * @return array<int, string>
     */
    private function nameHeaders(array $cells): array
    {
        $headers = [];
        $seen = [];

        foreach ($cells as $index => $cell) {
            $name = $cell !== '' ? $cell : __('Column :number', ['number' => $index + 1]);

            $seen[$name] = ($seen[$name] ?? 0) + 1;

            $headers[] = $seen[$name] > 1
                ? $name.' ('.$seen[$name].')'
                : $name;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function isBlank(array $cells): bool
    {
        return array_filter($cells, fn (string $cell): bool => $cell !== '') === [];
    }

    /**
     * A new reader that keeps every row.
     *
     * Each pass gets a new reader. simple-excel keeps the position and the
     * headings on the instance, so a second pass on the same reader starts
     * at a position that depends on the first pass.
     *
     * @throws UnreadableFileException
     */
    private function reader(): SpreadsheetReader
    {
        $extension = Str::lower(pathinfo($this->path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw UnreadableFileException::unparseable($this->path);
        }

        try {
            /*
             * Give the type, so that a CSV file with a .txt extension is read
             * as CSV.
             */
            $reader = SpreadsheetReader::create($this->path, $extension === 'xlsx' ? 'xlsx' : 'csv')
                ->preserveEveryRow();
        } catch (Throwable $exception) {
            throw UnreadableFileException::unparseable($this->path, $exception);
        }

        /*
         * Excel in many European locales writes CSV files with semicolons.
         * If the engine reads such a file with commas, the full row is in one
         * column. This call has no effect on an XLSX file.
         *
         * Do not chain this call. The setters of simple-excel return self,
         * and the chain then loses the type of this subclass.
         */
        $reader->useDelimiter($this->delimiter());

        return $reader;
    }

    /**
     * The comma, semicolon or tab that occurs most on the first line.
     */
    private function delimiter(): string
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = fgets($handle) ?: '';
        fclose($handle);

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        $delimiter = array_key_first($counts);

        return $counts[$delimiter] > 0 ? $delimiter : ',';
    }

    /**
     * The value of a cell, as the text that a person typed.
     *
     * A float that is a whole number loses its decimal part, so 1234.0
     * becomes 1234. A float with a fraction keeps it.
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'true';
        }

        if (is_float($value)) {
            $value = $value === floor($value) && is_finite($value)
                ? (string) (int) $value
                : (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (! is_scalar($value)) {
            return '';
        }

        /*
         * Remove the byte order mark that Windows puts before the first cell.
         * If it stays, it is part of the first heading, and the heading does
         * not agree with the column that it looks like.
         */
        return trim(Str::chopStart(trim((string) $value), "\u{FEFF}"));
    }
}
