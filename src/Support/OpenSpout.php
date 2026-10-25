<?php

namespace Bernskiold\LaravelImportEngine\Support;

use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use ReflectionProperty;

/**
 * Makes the openspout objects that changed between version 4 and 5.
 *
 * In version 4, options, styles and sheet views are mutable objects with
 * setters. In version 5, they are readonly objects with constructors.
 * The engine supports the two versions.
 *
 * @internal
 */
final class OpenSpout
{
    /**
     * A copy of the reader options with SHOULD_PRESERVE_EMPTY_ROWS set.
     *
     * @template TOptions of object
     *
     * @param  TOptions  $options
     * @return TOptions
     */
    public static function preserveEmptyRows(object $options): object
    {
        $property = new ReflectionProperty($options, 'SHOULD_PRESERVE_EMPTY_ROWS');

        if (! $property->isReadOnly()) {
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

            return $options;
        }

        return new ($options::class)(...[
            ...get_object_vars($options),
            'SHOULD_PRESERVE_EMPTY_ROWS' => true,
        ]);
    }

    public static function style(bool $bold = false, bool $wrap = false): Style
    {
        if (method_exists(Style::class, 'setFontBold')) {
            $style = new Style;

            if ($bold) {
                $style->setFontBold();
            }

            if ($wrap) {
                $style->setShouldWrapText();
            }

            return $style;
        }

        return new Style(fontBold: $bold, shouldWrapText: $wrap ?: null);
    }

    public static function sheetViewWithFrozenRows(int $row): SheetView
    {
        if (method_exists(SheetView::class, 'setFreezeRow')) {
            return (new SheetView)->setFreezeRow($row);
        }

        return new SheetView(freezeRow: $row);
    }
}
