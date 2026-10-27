<?php

namespace Bernskiold\LaravelImportEngine\Events;

use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The run stopped before the end of the file.
 *
 * The reason is on the record, as failure_reason. Rows that failed do not
 * cause this event. They are entries in the report of a completed run.
 */
class ImportFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Import $import,
    ) {}
}
