<?php

use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

it('reads the headings and the rows under them', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\nPepsi,5678\n"));

    expect($sheet->headers())->toBe(['Client', 'Code'])
        ->and($sheet->count())->toBe(2)
        ->and($sheet->preview()[0])->toBe([
            'line' => 2,
            'values' => ['Client' => 'Sony', 'Code' => '1234'],
        ]);
});

it('finds the headings under a title row', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client list — September\n\nClient,Code\nSony,1234\n"));

    expect($sheet->headers())->toBe(['Client', 'Code'])
        ->and($sheet->preview()[0]['line'])->toBe(4);
});

it('drops rows that are empty', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\n,\n\nPepsi,5678\n"));

    expect($sheet->count())->toBe(2);
});

it('gives each row the line number of the file, with the gaps', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\n,\nPepsi,5678\n"));

    expect(array_column($sheet->preview(), 'line'))->toBe([2, 4]);
});

it('keeps a blank column in its position and gives duplicates different names', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,,Notes,Notes\nSony,x,a,b\n"));

    expect($sheet->headers())->toBe(['Client', 'Column 2', 'Notes', 'Notes (2)'])
        ->and($sheet->preview()[0]['values'])->toBe([
            'Client' => 'Sony',
            'Column 2' => 'x',
            'Notes' => 'a',
            'Notes (2)' => 'b',
        ]);
});

it('reads a file with semicolons', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client;Code\nSony;1234\n"));

    expect($sheet->headers())->toBe(['Client', 'Code'])
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Sony', 'Code' => '1234']);
});

it('reads a file with tabs', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client\tCode\nSony\t1234\n", 'txt'));

    expect($sheet->headers())->toBe(['Client', 'Code']);
});

it('removes the byte order mark from the first heading', function () {
    $sheet = Spreadsheet::open(spreadsheet("\u{FEFF}Client,Code\nSony,1234\n"));

    expect($sheet->headers())->toBe(['Client', 'Code']);
});

it('reads a csv file with a txt extension', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\n", 'txt'));

    expect($sheet->headers())->toBe(['Client', 'Code'])
        ->and($sheet->count())->toBe(1);
});

it('reads an excel file, with numbers as the text that was typed', function () {
    $sheet = Spreadsheet::open(workbook([
        ['Client mapping'],
        [],
        ['Client', 'Code', 'Share'],
        ['Sony', 1234.0, 0.25],
        [null, null, null],
        ['Pepsi', 5678, 1.5],
    ]));

    expect($sheet->headers())->toBe(['Client', 'Code', 'Share'])
        ->and($sheet->preview())->toBe([
            ['line' => 4, 'values' => ['Client' => 'Sony', 'Code' => '1234', 'Share' => '0.25']],
            ['line' => 6, 'values' => ['Client' => 'Pepsi', 'Code' => '5678', 'Share' => '1.5']],
        ]);
});

it('can read the rows more than one time', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\nPepsi,5678\n"));

    expect($sheet->count())->toBe(2)
        ->and($sheet->count())->toBe(2)
        ->and($sheet->preview(1))->toHaveCount(1);
});

it('gives the path of the file', function () {
    $path = spreadsheet("Client,Code\nSony,1234\n");

    expect(Spreadsheet::open($path)->path())->toBe($path);
});

it('does not open a file that is not there', function () {
    Spreadsheet::open('/tmp/nothing-here-'.uniqid().'.csv');
})->throws(UnreadableFileException::class);

it('does not open an ods file', function () {
    Spreadsheet::open(spreadsheet("Client,Code\nSony,1234\n", 'ods'));
})->throws(UnreadableFileException::class);

it('reads a file with one column', function () {
    $sheet = Spreadsheet::open(spreadsheet("Email\nada@example.com\n\ngrace@example.com\n"));

    expect($sheet->headers())->toBe(['Email'])
        ->and($sheet->preview())->toBe([
            ['line' => 2, 'values' => ['Email' => 'ada@example.com']],
            ['line' => 4, 'values' => ['Email' => 'grace@example.com']],
        ]);
});

it('reads an excel file with one column', function () {
    $sheet = Spreadsheet::open(workbook([['Email'], ['ada@example.com']]));

    expect($sheet->headers())->toBe(['Email'])
        ->and($sheet->count())->toBe(1);
});

it('does not open a file that has no filled cells', function () {
    Spreadsheet::open(spreadsheet("\n,\n\n"));
})->throws(UnreadableFileException::class);

it('does not open a file that is not a spreadsheet', function () {
    Spreadsheet::open(spreadsheet('this is not a zip archive', 'xlsx'));
})->throws(UnreadableFileException::class);

it('reads a csv file in Windows-1252', function () {
    $sheet = Spreadsheet::open(spreadsheet(mb_convert_encoding("Client,City\nNestlé,Zürich\n", 'Windows-1252', 'UTF-8')));

    expect($sheet->encoding())->toBe('Windows-1252')
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Nestlé', 'City' => 'Zürich']);
});

it('reads the unicode text export of Excel', function () {
    $sheet = Spreadsheet::open(spreadsheet("\xFF\xFE".mb_convert_encoding("Client\tCity\nNestlé\tZürich\n", 'UTF-16LE', 'UTF-8'), 'txt'));

    expect($sheet->encoding())->toBe('UTF-16LE')
        ->and($sheet->headers())->toBe(['Client', 'City'])
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Nestlé', 'City' => 'Zürich']);
});

it('reads a csv file in UTF-8 as UTF-8', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,City\nNestlé,Zürich\n"));

    expect($sheet->encoding())->toBe('UTF-8')
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Nestlé', 'City' => 'Zürich']);
});

it('uses the configured encoding for a file that is not UTF-8', function () {
    config()->set('import-engine.csv_fallback_encoding', 'ISO-8859-2');

    $sheet = Spreadsheet::open(spreadsheet(mb_convert_encoding("Client,City\nŁódź,Kraków\n", 'ISO-8859-2', 'UTF-8')));

    expect($sheet->encoding())->toBe('ISO-8859-2')
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Łódź', 'City' => 'Kraków']);
});

it('keeps the time of an excel date cell that has one', function () {
    $style = method_exists(Style::class, 'setFormat')
        ? (new Style)->setFormat('yyyy-mm-dd hh:mm')
        : new Style(format: 'yyyy-mm-dd hh:mm');

    $path = tempnam(sys_get_temp_dir(), 'import-engine').'.xlsx';

    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    $writer->addRow(['Name', 'Sent']);

    foreach (['Ada' => '2026-10-25 00:00:00', 'Grace' => '2026-10-25 14:30:00'] as $name => $sent) {
        $writer->addRow(new Row([
            Cell::fromValue($name),
            new DateTimeCell(new DateTimeImmutable($sent), $style),
        ]));
    }

    $writer->close();

    expect(array_column(array_column(Spreadsheet::open($path)->preview(), 'values'), 'Sent'))->toBe(['2026-10-25', '2026-10-25 14:30:00']);
});
