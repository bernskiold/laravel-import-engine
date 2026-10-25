<?php

namespace Bernskiold\LaravelImportEngine\Events;

use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The run read the file to the end. The report is on the record.
 */
class ImportCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Import $import,
    ) {}
}
