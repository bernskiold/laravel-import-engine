<?php

namespace Bernskiold\LaravelImportEngine\Enums;

/**
 * The state of one import run.
 *
 * A run starts as pending. It is processing while the queue reads the file.
 * Then it is completed or failed. A run that read the file to the end is
 * completed, also if some rows failed. The report tells what occurred to each
 * row. Failed means that the run itself stopped.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Processing => __('Importing'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * The tone of the status: neutral, info, success, warning or danger.
     *
     * Use it to select a tag or a colour in your interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * True when the run can not change again.
     *
     * A screen that polls a run can stop when this is true.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Processing => false,
        };
    }
}
