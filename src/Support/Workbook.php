<?php

namespace Bernskiold\LaravelImportEngine\Support;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\WriterInterface;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An Excel file that a person downloads, completes and gives back to an
 * importer.
 *
 * Use it for a blank template and for an export of the current records.
 * Both are the same type of file, so an importer can read each of them.
 *
 * The headings are bold and stay in view. Each column has the width of its
 * longest value.
 *
 * The file is an Excel workbook and not a CSV file. Excel removes leading
 * zeros from a CSV file, and it can change characters that are not ASCII.
 */
final class Workbook
{
    /**
     * The minimum and maximum width of a column, in characters. A long value
     * wraps after the maximum width.
     */
    private const MIN_WIDTH = 10;

    private const MAX_WIDTH = 60;

    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, string|int|null>>  $rows  In the order of the headings.
     */
    public static function download(string $fileName, array $headings, iterable $rows = []): StreamedResponse
    {
        /*
         * Keep all the rows in memory. The widths of the columns come from
         * the values, so the class must read all the rows first. These files
         * are small.
         */
        $rows = collect($rows)->map(fn (array $row): array => array_values($row))->all();

        /*
         * Use a response that Laravel streams. SimpleExcelWriter::toBrowser()
         * calls exit, which stops the framework: there is no terminating
         * middleware, no session write, and a test can not examine it.
         */
        $response = new StreamedResponse(
            function () use ($fileName, $headings, $rows): void {
                $writer = SimpleExcelWriter::streamDownload(
                    $fileName,
                    writerCallback: fn (WriterInterface $writer) => $writer->openToFile('php://output'),
                )
                    ->setHeaderStyle(OpenSpout::style(bold: true))
                    ->addHeader($headings);

                self::layOut($writer->getWriter(), $headings, $rows);

                /*
                 * Wrap the text, so that a cell with one value on each line
                 * shows each value on its line.
                 */
                $style = OpenSpout::style(wrap: true);

                foreach ($rows as $row) {
                    $writer->addRow(new Row(array_map(
                        fn (string|int|null $value): Cell => self::cell($value, $style),
                        $row,
                    )));
                }

                $writer->close();
            },
            200,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $fileName,
            str_replace('%', '', Str::ascii($fileName)),
        ));

        return $response;
    }

    /**
     * Freeze the heading row and set the width of each column.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    private static function layOut(WriterInterface $writer, array $headings, array $rows): void
    {
        if (! $writer instanceof AbstractWriterMultiSheets) {
            return;
        }

        $sheet = $writer->getCurrentSheet();

        $sheet->setSheetView(OpenSpout::sheetViewWithFrozenRows(2));

        foreach (array_values($headings) as $index => $heading) {
            $longest = collect($rows)
                ->map(fn (array $row): string => (string) ($row[$index] ?? ''))
                ->push($heading)
                ->flatMap(fn (string $value): array => preg_split('/\R/', $value) ?: [])
                ->map(fn (string $line): int => mb_strlen($line))
                ->max();

            $sheet->setColumnWidth(
                (float) min(max($longest + 2, self::MIN_WIDTH), self::MAX_WIDTH),
                $index + 1,
            );
        }
    }

    /**
     * A value as a cell. Text is always text.
     *
     * Do not use Cell::fromValue(). It writes a string that starts with "="
     * as a formula, and Excel runs the formula when it opens the file.
     */
    private static function cell(string|int|null $value, Style $style): Cell
    {
        return match (true) {
            $value === null, $value === '' => new EmptyCell(null, $style),
            is_int($value) => new NumericCell($value, $style),
            default => new StringCell($value, $style),
        };
    }
}
