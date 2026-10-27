<?php

namespace Bernskiold\LaravelImportEngine\Actions;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Contracts\ImportType;
use Bernskiold\LaravelImportEngine\Enums\ImportStatus;
use Bernskiold\LaravelImportEngine\ImportEngine;
use Bernskiold\LaravelImportEngine\Jobs\RunImportJob;
use Bernskiold\LaravelImportEngine\Models\Import;
use Bernskiold\LaravelImportEngine\Testing\StartImportFake;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Keep the file, make the record and send the run to the queue.
 *
 * Two things must occur, in this order:
 *
 * 1. The file goes from the temporary upload directory to a disk. The
 *    temporary files are deleted on a timer, and the queue worker can be
 *    on a different machine.
 * 2. The record is saved before the job is sent. Thus a fast queue can not
 *    start a job for a record that does not exist.
 */
class StartImport
{
    /**
     * @param  UploadedFile|string  $file  An upload, or the path of a local file.
     * @param  array<string, string|null>  $map  The field for each column heading of the file.
     * @param  array<string, mixed>  $options  The values that the importer needs in addition to the file.
     * @param  array<string, mixed>  $attributes  More columns for your own import model.
     */
    public function execute(
        ImportType&BackedEnum $type,
        UploadedFile|string $file,
        array $map = [],
        array $options = [],
        ?Authenticatable $user = null,
        array $attributes = [],
        ?string $fileName = null,
    ): Import {
        $disk = ImportEngine::disk();

        $path = $file instanceof UploadedFile
            ? $file->storeAs(ImportEngine::directory(), $this->storedName($file), $disk)
            : Storage::disk($disk)->putFileAs(ImportEngine::directory(), new File($file), $this->storedName($file));

        if (! is_string($path)) {
            throw new RuntimeException('The import file could not be stored.');
        }

        $class = ImportEngine::model();

        $import = new $class;

        $import->forceFill([
            ...$attributes,
            'type' => $type,
            'status' => ImportStatus::Pending,
            'file_name' => $fileName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file)),
            'file_path' => $path,
            'disk' => $disk,
            'column_map' => $map,
            'options' => $options,
            'user_id' => $user?->getAuthIdentifier() ?? Auth::id(),
        ])->save();

        $this->dispatch($import);

        return $import;
    }

    /**
     * A random name for the stored file, with the extension of the file.
     *
     * Laravel guesses an extension from the MIME type, and a CSV upload is
     * often "text/plain", which gives ".txt". The file keeps the extension
     * that the person gave it. When it has none, the guess of Laravel is
     * used.
     */
    protected function storedName(UploadedFile|string $file): string
    {
        $extension = Str::lower($file instanceof UploadedFile
            ? $file->getClientOriginalExtension()
            : pathinfo($file, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            $extension = $file instanceof UploadedFile ? (string) $file->guessExtension() : '';
        }

        $name = Str::random(40);

        return $extension !== '' ? $name.'.'.$extension : $name;
    }

    /**
     * Replace this action in the container with a fake that does not send
     * the job. The fake keeps the file and makes the record.
     */
    public static function fake(): StartImportFake
    {
        $fake = new StartImportFake;

        app()->instance(static::class, $fake);

        return $fake;
    }

    /**
     * Send the run to the queue, after the database transaction commits.
     */
    protected function dispatch(Import $import): void
    {
        RunImportJob::dispatch($import)->afterCommit();
    }
}
