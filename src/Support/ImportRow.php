<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * One row of the file, after the column map, with the values keyed by field.
 *
 * The accessors are tolerant of the values that people type:
 *
 * - get() reads a placeholder such as "-", "n/a" or "TBC" as no value.
 * - values() splits a cell that holds a list, such as "1234, 1235; 1236".
 * - lines() splits a cell on line breaks only, for names that contain commas.
 * - integer() reads "#7" and "7.0" as 7.
 * - boolean() reads "yes", "y", "true", "1" and "x" as true.
 * - date() reads "2026-10-25", "25/10/2026" and "25 Oct 2026" as a date.
 *
 * The line is the line number in the file, from one. It agrees with the row
 * number that the person sees in the spreadsheet.
 */
final readonly class ImportRow
{
    /**
     * The cell values that mean "no value", in lowercase.
     *
     * Keep this list short. Do not add words such as "none" or "unknown",
     * because they can be real values.
     */
    private const PLACEHOLDERS = ['-', '--', '–', '—', 'n/a', 'n.a.', 'na', '#n/a', 'null', 'tbc', 'tbd', '?'];

    private const TRUE_VALUES = ['yes', 'y', 'true', '1', 'x'];

    private const FALSE_VALUES = ['no', 'n', 'false', '0'];

    /**
     * @param  array<string, string>  $values  The values, keyed by field.
     * @param  array<string, string>  $original  The values, keyed by the column headings of the file.
     */
    public function __construct(
        public int $line,
        private array $values = [],
        private array $original = [],
    ) {}

    /**
     * The value of the field, or null when the row has no real value.
     */
    public function get(string $field): ?string
    {
        $value = trim($this->values[$field] ?? '');

        if ($value === '' || self::isPlaceholder($value)) {
            return null;
        }

        return $value;
    }

    public function has(string $field): bool
    {
        return $this->get($field) !== null;
    }

    /**
     * The value of the field as a list.
     *
     * Commas, semicolons, pipes, slashes and line breaks separate the items.
     * Blank items and duplicate items are removed.
     *
     * @return array<int, string>
     */
    public function values(string $field): array
    {
        return $this->split($field, '/[,;|\/\r\n]+/');
    }

    /**
     * The value of the field as a list, with one item on each line.
     *
     * Use this for names. A name such as "Mars, Incorporated" contains a
     * comma, so only a line break can separate two names.
     *
     * @return array<int, string>
     */
    public function lines(string $field): array
    {
        return $this->split($field, '/[\r\n]+/');
    }

    /**
     * The value of the field as a whole number, or null.
     *
     * "#4", "4." and "4.0" are all 4. A value such as "top 10" or "4a" is
     * null. The engine does not guess a number.
     */
    public function integer(string $field): ?int
    {
        $number = $this->number($field);

        if ($number === null) {
            return null;
        }

        return $number === floor($number) ? (int) $number : null;
    }

    /**
     * The value of the field as a number, or null.
     *
     * A leading "#" is removed. Thousands separators are not accepted.
     */
    public function number(string $field): ?float
    {
        $value = $this->get($field);

        if ($value === null) {
            return null;
        }

        $digits = ltrim($value, '#');

        if (! is_numeric($digits)) {
            return null;
        }

        return (float) $digits;
    }

    /**
     * The value of the field as a boolean.
     *
     * "yes", "y", "true", "1" and "x" are true. "no", "n", "false" and "0"
     * are false. Case is ignored. A value that is not one of these, or no
     * value, gives the default.
     */
    public function boolean(string $field, ?bool $default = null): ?bool
    {
        $value = $this->get($field);

        if ($value === null) {
            return $default;
        }

        return match (true) {
            in_array(Str::lower($value), self::TRUE_VALUES, true) => true,
            in_array(Str::lower($value), self::FALSE_VALUES, true) => false,
            default => $default,
        };
    }

    /**
     * The value of the field as a date, or null.
     *
     * With a format, the value must have that format. Without a format, the
     * engine reads these values:
     *
     * - "2026-10-25", "2026-10-25 14:30" and "2026-10-25T14:30:00".
     * - "25/10/2026", "25.10.2026" and "25-10-2026", with the day first.
     *   Set the "date_order" config to "mdy" to read the month first.
     * - A value with the name of the month, for example "25 Oct 2026".
     *
     * A value that is not a valid date is null. The engine does not guess.
     * An XLSX date cell is read as "2026-10-25", or with the time when the
     * time is not midnight.
     */
    public function date(string $field, ?string $format = null, ?string $timezone = null): ?CarbonImmutable
    {
        $value = $this->get($field);

        if ($value === null) {
            return null;
        }

        if ($format !== null) {
            return self::fromFormat($format, $value, $timezone);
        }

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/', $value, $matches)) {
            $format = match (true) {
                ! isset($matches[1]) => '!Y-m-d',
                isset($matches[2]) => '!Y-m-d?H:i:s',
                default => '!Y-m-d?H:i',
            };

            return self::fromFormat($format, $value, $timezone);
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $matches)) {
            [$first, $second] = [(int) $matches[1], (int) $matches[2]];
            [$day, $month] = config('import-engine.date_order', 'dmy') === 'mdy' ? [$second, $first] : [$first, $second];

            return checkdate($month, $day, (int) $matches[3])
                ? CarbonImmutable::create((int) $matches[3], $month, $day, 0, 0, 0, $timezone)
                : null;
        }

        if (preg_match('/[a-z]{3,}/i', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Each mapped field of the row, with null for an empty cell or a
     * placeholder.
     *
     * The engine gives this to the validator.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return collect(array_keys($this->values))
            ->mapWithKeys(fn (string $field): array => [$field => $this->get($field)])
            ->all();
    }

    /**
     * The mapped values that the row has, keyed by field.
     *
     * Placeholders are not included.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return collect(array_keys($this->values))
            ->mapWithKeys(fn (string $field): array => [$field => $this->get($field)])
            ->filter(fn (?string $value): bool => $value !== null)
            ->all();
    }

    /**
     * The row as it is in the file, keyed by the column headings of the file.
     *
     * @return array<string, string>
     */
    public function original(): array
    {
        return $this->original;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $field, string $separators): array
    {
        $value = $this->get($field);

        if ($value === null) {
            return [];
        }

        return collect(preg_split($separators, $value) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->reject(fn (string $part): bool => $part === '' || self::isPlaceholder($part))
            ->unique()
            ->values()
            ->all();
    }

    private static function fromFormat(string $format, string $value, ?string $timezone): ?CarbonImmutable
    {
        /*
         * Without "!" or "|", PHP takes the fields that the format does not
         * name from the current time.
         */
        if (! str_contains($format, '!') && ! str_contains($format, '|')) {
            $format = '!'.$format;
        }

        try {
            $date = CarbonImmutable::createFromFormat($format, $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }

    private static function isPlaceholder(string $value): bool
    {
        return in_array(Str::lower($value), self::PLACEHOLDERS, true);
    }
}
