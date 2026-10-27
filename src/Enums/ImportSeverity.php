<?php

namespace Bernskiold\LaravelImportEngine\Enums;

/**
 * How much attention the outcome of a row needs.
 *
 * There are three levels, one for each action: do nothing, examine the row,
 * or correct the row.
 */
enum ImportSeverity: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Success => __('Imported'),
            self::Warning => __('Needs attention'),
            self::Error => __('Failed'),
        };
    }

    /**
     * The tone of the severity: success, warning or danger.
     *
     * Use it to select a tag or a colour in your interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
        };
    }
}
