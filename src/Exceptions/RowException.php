<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

use RuntimeException;

/**
 * One row can not be imported, and the message is for the person.
 *
 * Throw it in importRow() to stop the row. The report records the row as
 * failed, with the message as its note. The engine does not send it to the
 * exception handler.
 *
 *     throw new RowException(__('The date is in the future.'));
 */
class RowException extends RuntimeException
{
    //
}
