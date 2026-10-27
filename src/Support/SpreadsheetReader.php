<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * The simple-excel reader, with one more option.
 *
 * The preserveEmptyRows() option of simple-excel sets only the XLSX reader.
 * The CSV reader keeps its own options object, which simple-excel does not
 * change. Thus a CSV reader removes empty lines before it counts them.
 *
 * The engine must count every line. The row number in a report is the line
 * number in the file, so that a person can find the row in the spreadsheet.
 * If the reader removes a blank line, each row number after it is incorrect.
 *
 * The withOption() helper of simple-excel sets the flag on openspout 4 and
 * 5. The reader reads the options when it opens the file, after this call.
 *
 * The engine does not accept ODS files. simple-excel has no options object
 * for ODS, so this flag can not be set for that format.
 *
 * @internal Use Spreadsheet to read a file.
 */
class SpreadsheetReader extends SimpleExcelReader
{
    public function preserveEveryRow(): static
    {
        $this->csvOptions = $this->withOption($this->csvOptions, 'SHOULD_PRESERVE_EMPTY_ROWS', true);
        $this->xlsxOptions = $this->withOption($this->xlsxOptions, 'SHOULD_PRESERVE_EMPTY_ROWS', true);

        return $this;
    }
}
