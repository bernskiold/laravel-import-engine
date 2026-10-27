<?php

namespace Bernskiold\LaravelImportEngine\Exceptions;

use RuntimeException;

/**
 * The file can not be imported, and the cause is the file.
 *
 * The message is for the person who selected the file. The engine records it
 * as the reason for the failure, and it does not send it to the exception
 * handler: the application did not make an error.
 */
class ImportFileException extends RuntimeException
{
    //
}
