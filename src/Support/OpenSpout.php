<?php

namespace Bernskiold\LaravelImportEngine\Support;

use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;

/**
 * Makes the openspout objects that changed between version 4 and 5.
 *
 * In version 4, styles and sheet views are mutable objects with setters.
 * In version 5, they are readonly objects with constructors.
 * The engine supports the two versions.
 *
 * @internal
 */
final class OpenSpout
{
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
