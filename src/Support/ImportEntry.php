<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Enums\ImportSeverity;

/**
 * One line of the report: what occurred to one row of the file.
 *
 * - The row is the line number in the file, with the headings as line one.
 *   It is null when an importer does not know the line, for example when it
 *   reports on a record that many rows supplied.
 * - The subject is the name of the row, for example a client name or an
 *   email address. Keep it also when the row was ignored, so that a person
 *   can identify the row without the file.
 * - The notes tell what the importer did. A row can have many notes.
 * - The warnings tell about problems that did not stop the row. A row that
 *   was created can have warnings.
 */
final readonly class ImportEntry
{
    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public ?int $row,
        public ImportOutcome $outcome,
        public ?string $subject = null,
        public array $notes = [],
        public array $warnings = [],
    ) {}

    public function severity(): ImportSeverity
    {
        return $this->outcome->severity();
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return array{row: int|null, outcome: string, subject: string|null, notes: array<int, string>, warnings: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'outcome' => $this->outcome->value,
            'subject' => $this->subject,
            'notes' => $this->notes,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Make an entry from its stored form.
     *
     * An outcome that the enum does not have becomes Failed. Thus an old
     * report does not stop the screen that shows it.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        return new self(
            row: isset($entry['row']) ? (int) $entry['row'] : null,
            outcome: ImportOutcome::tryFrom((string) ($entry['outcome'] ?? '')) ?? ImportOutcome::Failed,
            subject: isset($entry['subject']) ? (string) $entry['subject'] : null,
            notes: array_values(array_map('strval', (array) ($entry['notes'] ?? []))),
            warnings: array_values(array_map('strval', (array) ($entry['warnings'] ?? []))),
        );
    }
}
