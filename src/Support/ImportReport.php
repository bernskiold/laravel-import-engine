<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Enums\ImportSeverity;
use Illuminate\Support\Collection;

/**
 * What an import did, row by row, and the totals.
 *
 * The importer records one entry for each row. Each count comes from the
 * entries, so a count can not be different from the list.
 *
 * The preview and the real run make the same report. The preview is the same
 * work in a transaction that is rolled back.
 *
 * Tallies are counts that are not rows, for example the number of links
 * that the run made. They have keys and not labels, because a stored report
 * can be shown in a different language later. The importer gives the
 * labels, see Importer::tallies().
 */
final class ImportReport
{
    /**
     * @param  array<int, ImportEntry>  $entries
     * @param  array<string, int>  $tallies
     */
    public function __construct(
        private array $entries = [],
        private array $tallies = [],
    ) {}

    public function record(ImportEntry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function created(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Created, $row, $subject, $notes, $warnings);
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function updated(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Updated, $row, $subject, $notes, $warnings);
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function unchanged(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Unchanged, $row, $subject, $notes, $warnings);
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function conflicted(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Conflicted, $row, $subject, $notes, $warnings);
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function skipped(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Skipped, $row, $subject, $notes, $warnings);
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function failed(ImportRow|int|null $row = null, ?string $subject = null, array $notes = [], array $warnings = []): self
    {
        return $this->add(ImportOutcome::Failed, $row, $subject, $notes, $warnings);
    }

    /**
     * Add the entries and the tallies of another report to this report.
     */
    public function merge(ImportReport $other): self
    {
        foreach ($other->entries as $entry) {
            $this->entries[] = $entry;
        }

        foreach ($other->tallies as $key => $count) {
            $this->tally($key, $count);
        }

        return $this;
    }

    public function tally(string $key, int $by = 1): self
    {
        $this->tallies[$key] = ($this->tallies[$key] ?? 0) + $by;

        return $this;
    }

    /**
     * @return Collection<int, ImportEntry>
     */
    public function entries(): Collection
    {
        return collect($this->entries);
    }

    /**
     * @return array<string, int>
     */
    public function tallies(): array
    {
        return $this->tallies;
    }

    public function rows(): int
    {
        return count($this->entries);
    }

    public function countOf(ImportOutcome $outcome): int
    {
        return $this->entries()
            ->filter(fn (ImportEntry $entry): bool => $entry->outcome === $outcome)
            ->count();
    }

    public function countOfSeverity(ImportSeverity $severity): int
    {
        return $this->entries()
            ->filter(fn (ImportEntry $entry): bool => $entry->severity() === $severity)
            ->count();
    }

    /**
     * The number of entries that have one or more warnings.
     */
    public function countWithWarnings(): int
    {
        return $this->entries()
            ->filter(fn (ImportEntry $entry): bool => $entry->hasWarnings())
            ->count();
    }

    /**
     * The number of rows that a person must examine.
     *
     * These are all the rows that do not have a successful outcome.
     */
    public function needingAttention(): int
    {
        return $this->rows() - $this->countOfSeverity(ImportSeverity::Success);
    }

    /**
     * True when no row changed data.
     *
     * A second run of the same file gives this result.
     */
    public function changedNothing(): bool
    {
        return $this->entries()->every(
            fn (ImportEntry $entry): bool => $entry->outcome === ImportOutcome::Unchanged,
        );
    }

    /**
     * The counts of the report, for a list of imports.
     *
     * The record stores the summary in a column of its own. Thus a list can
     * show the counts without the full report.
     *
     * @return array{rows: int, created: int, updated: int, unchanged: int, conflicted: int, skipped: int, failed: int, needing_attention: int, with_warnings: int}
     */
    public function summary(): array
    {
        return [
            'rows' => $this->rows(),
            ...collect(ImportOutcome::cases())
                ->mapWithKeys(fn (ImportOutcome $outcome): array => [$outcome->value => $this->countOf($outcome)])
                ->all(),
            'needing_attention' => $this->needingAttention(),
            'with_warnings' => $this->countWithWarnings(),
        ];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, tallies: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'entries' => array_map(fn (ImportEntry $entry): array => $entry->toArray(), $this->entries),
            'tallies' => $this->tallies,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function fromArray(array $report): self
    {
        return new self(
            entries: array_map(
                fn (array $entry): ImportEntry => ImportEntry::fromArray($entry),
                array_values((array) ($report['entries'] ?? [])),
            ),
            tallies: array_map('intval', (array) ($report['tallies'] ?? [])),
        );
    }

    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    private function add(ImportOutcome $outcome, ImportRow|int|null $row, ?string $subject, array $notes, array $warnings): self
    {
        return $this->record(new ImportEntry(
            row: $row instanceof ImportRow ? $row->line : $row,
            outcome: $outcome,
            subject: $subject,
            notes: array_values($notes),
            warnings: array_values($warnings),
        ));
    }
}
