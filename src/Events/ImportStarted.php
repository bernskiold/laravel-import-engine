<?php

namespace Bernskiold\LaravelImportEngine\Events;

use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The queue started to read the file.
 */
class ImportStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Import $import,
    ) {}
}
