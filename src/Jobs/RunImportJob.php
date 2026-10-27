<?php

namespace Bernskiold\LaravelImportEngine\Jobs;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Actions\RunImport;
use Bernskiold\LaravelImportEngine\Events\ImportFailed;
use Bernskiold\LaravelImportEngine\Exceptions\ImportAlreadyStartedException;
use Bernskiold\LaravelImportEngine\Exceptions\ImportFileException;
use Bernskiold\LaravelImportEngine\ImportEngine;
use Bernskiold\LaravelImportEngine\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Run an import on the queue.
 *
 * The person who uploaded the file does not have to wait. The screen can
 * poll the record, and the run continues when the tab closes.
 *
 * The job does not try again. The importer can be idempotent for each row,
 * but a second attempt writes a second report over the first. A run that
 * fails is a run that a person examines and starts again.
 */
class RunImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public Import $import,
    ) {
        $this->timeout = (int) config('import-engine.queue.timeout', 900);

        $this->onConnection(config('import-engine.queue.connection'));
        $this->onQueue(config('import-engine.queue.queue'));
    }

    public function handle(RunImport $run): void
    {
        /*
         * The import writes as the person who started it. Activity logs and
         * "created by" columns read the authenticated user, and a queue
         * worker has none. The previous user comes back after the run,
         * because a worker keeps its guards from one job to the next.
         */
        $previous = Auth::hasUser() ? Auth::user() : null;
        $user = $this->import->user;

        if ($user instanceof Authenticatable) {
            Auth::setUser($user);
        }

        try {
            $run->execute($this->import);
        } catch (ImportAlreadyStartedException) {
            /*
             * The queue delivered the job again, and another worker has the
             * record. Do nothing.
             */
            return;
        } catch (ImportFileException $exception) {
            /*
             * The file is incorrect, not the application. The record has the
             * reason for the person who uploaded it. Mark the job as failed,
             * and do not send the exception to the exception handler.
             */
            $this->fail($exception);
        } finally {
            if ($user instanceof Authenticatable) {
                $previous instanceof Authenticatable ? Auth::setUser($previous) : Auth::forgetUser();
            }
        }
    }

    /**
     * The job stopped in a place that the action can not catch, for example
     * a timeout. Without this, the record stays "processing", and a screen
     * that polls it waits forever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->import->refresh();

        if ($this->import->isSettled()) {
            return;
        }

        $this->import->fail($exception !== null
            ? ImportEngine::failureMessage($exception)
            : __('The import stopped because of an error in the application.'));

        ImportFailed::dispatch($this->import);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $type = $this->import->type;

        return [
            'import',
            'import:'.$this->import->getKey(),
            'import-type:'.($type instanceof BackedEnum ? $type->value : $type),
        ];
    }
}
