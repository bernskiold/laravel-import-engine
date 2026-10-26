<?php

namespace Bernskiold\LaravelImportEngine\Enums;

/**
 * What occurred to one row of the file.
 *
 * Each row gets one outcome. Thus the counts of the outcomes add up to the
 * number of rows in the file.
 *
 * - Unchanged is not the same as updated. Unchanged means that the record
 *   already had the values of the row. A second run of the same file is
 *   then a list of unchanged rows, and you can see that the import is
 *   idempotent.
 * - Conflicted is not a failure. It means that the file does not agree with
 *   the data that you have, and the importer did not change the data.
 *   A person must examine the row.
 */
enum ImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Conflicted = 'conflicted';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Created'),
            self::Updated => __('Updated'),
            self::Unchanged => __('Unchanged'),
            self::Conflicted => __('Conflicted'),
            self::Skipped => __('Skipped'),
            self::Failed => __('Failed'),
        };
    }

    public function severity(): ImportSeverity
    {
        return match ($this) {
            self::Created,
            self::Updated,
            self::Unchanged => ImportSeverity::Success,

            self::Conflicted,
            self::Skipped => ImportSeverity::Warning,

            self::Failed => ImportSeverity::Error,
        };
    }
}
