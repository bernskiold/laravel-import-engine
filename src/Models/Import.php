<?php

namespace Bernskiold\LaravelImportEngine\Models;

use BackedEnum;
use Bernskiold\LaravelImportEngine\Contracts\ImportType;
use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Enums\ImportStatus;
use Bernskiold\LaravelImportEngine\Exceptions\ImportEngineException;
use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\ImportEngine;
use Bernskiold\LaravelImportEngine\Importer;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One run of one importer on one file.
 *
 * The record keeps who gave the file, the column map, when the run started
 * and stopped, and the report. Only the run changes the record.
 *
 * The record is necessary because the report must stay after the screen
 * closes. A person can close the tab while the queue works, and come back
 * later to read the report.
 *
 * The file is kept on a disk, not in the temporary directory of Livewire.
 * The queue worker can be on a different machine, and the temporary files
 * are deleted on a timer.
 *
 * @property int $id
 * @property string $uuid
 * @property (ImportType&BackedEnum)|string $type
 * @property ImportStatus $status
 * @property string $file_name
 * @property string|null $file_path
 * @property string|null $disk
 * @property array<string, string|null>|null $column_map
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $report
 * @property array<string, int>|null $summary
 * @property string|null $failure_reason
 * @property int|string|null $user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $user
 */
class Import extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'status',
        'file_name',
        'file_path',
        'disk',
        'column_map',
        'options',
        'report',
        'summary',
        'failure_reason',
        'user_id',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function getTable(): string
    {
        return $this->table ?? (string) config('import-engine.table', 'imports');
    }

    /**
     * The UUID is a second key. The primary key stays an integer.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_filter([
            'type' => ImportEngine::types(),
            'status' => ImportStatus::class,
            'column_map' => 'array',
            'options' => 'array',
            'report' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ]);
    }

    /**
     * The person who started the import.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ImportEngine::userModel(), 'user_id');
    }

    /**
     * The importer for the type of this record.
     *
     * @throws ImportEngineException
     */
    public function importer(): Importer
    {
        return $this->importType()->importer();
    }

    /**
     * @throws ImportEngineException
     */
    public function importType(): ImportType&BackedEnum
    {
        if ($this->type instanceof ImportType) {
            return $this->type;
        }

        return ImportEngine::type((string) $this->type);
    }

    /**
     * A name for the import, for a person.
     */
    public function label(): string
    {
        try {
            $type = $this->importType()->label();
        } catch (ImportEngineException) {
            $type = (string) ($this->type instanceof BackedEnum ? $this->type->value : $this->type);
        }

        return $type.' – '.$this->file_name;
    }

    /*
    |---------------------------------------------------------------------------
    | The run
    |---------------------------------------------------------------------------
    |
    | Three transitions. Each one sets the status and its time together, so a
    | completed run always has a completion time.
    |
    */

    public function start(): void
    {
        $this->update([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function complete(ImportReport $report): void
    {
        $this->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
            'report' => $report->toArray(),
            'summary' => $report->summary(),
        ]);
    }

    /**
     * The run itself stopped.
     *
     * If there is a report, the record keeps it. A file that stopped at row
     * three hundred possibly imported the rows before it.
     */
    public function fail(string $reason, ?ImportReport $report = null): void
    {
        $this->update([
            'status' => ImportStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'report' => $report?->toArray() ?? $this->report,
            'summary' => $report?->summary() ?? $this->summary,
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === ImportStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === ImportStatus::Processing;
    }

    public function isCompleted(): bool
    {
        return $this->status === ImportStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ImportStatus::Failed;
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /*
    |---------------------------------------------------------------------------
    | The report
    |---------------------------------------------------------------------------
    */

    public function hasReport(): bool
    {
        return $this->report !== null;
    }

    /**
     * The stored report.
     *
     * If there is no report, this gives an empty report. Thus a caller can
     * get the counts without a check. A run that waits in the queue did
     * nothing, so zero is the correct count.
     */
    public function importReport(): ImportReport
    {
        return ImportReport::fromArray($this->report ?? []);
    }

    /**
     * One count of the summary: "rows", an outcome such as "created", or
     * "needing_attention" or "with_warnings".
     *
     * The summary is a column of its own, so this works also for a record
     * that was loaded without the report. A record without a summary, for
     * example a record from before the summary existed, reads the report.
     */
    public function countOf(ImportOutcome|string $key): int
    {
        $key = $key instanceof ImportOutcome ? $key->value : $key;

        $summary = $this->summary ?? ($this->hasReport() ? $this->importReport()->summary() : []);

        return (int) ($summary[$key] ?? 0);
    }

    /*
    |---------------------------------------------------------------------------
    | The file
    |---------------------------------------------------------------------------
    */

    public function hasFile(): bool
    {
        return $this->file_path !== null
            && $this->disk !== null
            && Storage::disk($this->disk)->exists($this->file_path);
    }

    /**
     * Give the callback a local copy of the file.
     *
     * The copy is deleted when the callback returns. The spreadsheet readers
     * must have a local path, and a file on S3 is not local.
     *
     * The copy keeps the extension of the file, because Spreadsheet selects
     * the reader from the extension.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $read
     * @return TReturn
     *
     * @throws UnreadableFileException
     */
    public function withLocalFile(callable $read): mixed
    {
        if (! $this->hasFile()) {
            throw UnreadableFileException::missing((string) $this->file_path);
        }

        $extension = pathinfo($this->file_name, PATHINFO_EXTENSION)
            ?: pathinfo((string) $this->file_path, PATHINFO_EXTENSION)
            ?: 'csv';

        /*
         * tempnam() makes the file that it names. The copy must have the
         * extension, so remove that file and use the name with the extension.
         * Without the unlink, each run leaves an empty file in the temporary
         * directory.
         */
        $reserved = tempnam(sys_get_temp_dir(), 'import');
        @unlink($reserved);

        $path = $reserved.'.'.$extension;

        $stream = Storage::disk((string) $this->disk)->readStream((string) $this->file_path);

        if ($stream === null) {
            throw UnreadableFileException::missing((string) $this->file_path);
        }

        try {
            file_put_contents($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return $read($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Give the callback the open file.
     *
     * A spreadsheet reads its rows when they are necessary. Thus the local
     * copy must stay until the last row is read. Read all the rows in the
     * callback, and do not keep the object after it.
     *
     * @template TReturn
     *
     * @param  callable(Spreadsheet): TReturn  $read
     * @return TReturn
     *
     * @throws UnreadableFileException
     */
    public function withSpreadsheet(callable $read): mixed
    {
        return $this->withLocalFile(fn (string $path): mixed => $read(Spreadsheet::open($path)));
    }

    /**
     * Delete the uploaded file. The record stays.
     */
    public function deleteFile(): void
    {
        if ($this->file_path === null || $this->disk === null) {
            return;
        }

        try {
            Storage::disk($this->disk)->delete($this->file_path);
        } catch (Throwable $exception) {
            /*
             * A file that the disk does not delete is not a reason to stop.
             * The exception handler gets the error.
             */
            report($exception);
        }
    }

    /*
    |---------------------------------------------------------------------------
    | Queries
    |---------------------------------------------------------------------------
    */

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, BackedEnum|string $type): Builder
    {
        return $query->where('type', $type instanceof BackedEnum ? $type->value : $type);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStartedBy(Builder $query, Authenticatable|int|string $user): Builder
    {
        return $query->where('user_id', $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
    }

    /**
     * Select all the columns except the report.
     *
     * A report can contain thousands of entries. Use this scope for a list
     * of imports, and read the counts with countOf().
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutReport(Builder $query): Builder
    {
        $table = $this->getTable();

        $columns = collect($this->getConnection()->getSchemaBuilder()->getColumnListing($table))
            ->reject(fn (string $column): bool => $column === 'report')
            ->map(fn (string $column): string => $table.'.'.$column)
            ->values()
            ->all();

        $query->select($columns);

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        $query->orderByDesc('created_at')->orderByDesc('id');

        return $query;
    }

    /**
     * The uploaded file goes when the record goes.
     *
     * A file on a disk that no record points to is a file that nobody can
     * find and nobody knows to delete.
     */
    protected static function booted(): void
    {
        static::deleted(function (Import $import): void {
            $import->deleteFile();
        });
    }
}
