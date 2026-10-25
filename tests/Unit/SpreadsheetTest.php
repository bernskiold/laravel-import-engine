<?php

use Bernskiold\LaravelImportEngine\Exceptions\UnreadableFileException;
use Bernskiold\LaravelImportEngine\Support\Spreadsheet;

it('reads the headings and the rows under them', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\nPepsi,5678\n"));

    expect($sheet->headers())->toBe(['Client', 'BFC'])
        ->and($sheet->count())->toBe(2)
        ->and($sheet->preview()[0])->toBe([
            'line' => 2,
            'values' => ['Client' => 'Sony', 'BFC' => '1234'],
        ]);
});

it('finds the headings under a title row', function () {
    $sheet = Spreadsheet::open(spreadsheet("WPP Client Mapping — September\n\nClient,BFC\nSony,1234\n"));

    expect($sheet->headers())->toBe(['Client', 'BFC'])
        ->and($sheet->preview()[0]['line'])->toBe(4);
});

it('drops rows that are empty', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\n,\n\nPepsi,5678\n"));

    expect($sheet->count())->toBe(2);
});

it('gives each row the line number of the file, with the gaps', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\n,\nPepsi,5678\n"));

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
    $sheet = Spreadsheet::open(spreadsheet("Client;BFC\nSony;1234\n"));

    expect($sheet->headers())->toBe(['Client', 'BFC'])
        ->and($sheet->preview()[0]['values'])->toBe(['Client' => 'Sony', 'BFC' => '1234']);
});

it('reads a file with tabs', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client\tBFC\nSony\t1234\n", 'txt'));

    expect($sheet->headers())->toBe(['Client', 'BFC']);
});

it('removes the byte order mark from the first heading', function () {
    $sheet = Spreadsheet::open(spreadsheet("\u{FEFF}Client,BFC\nSony,1234\n"));

    expect($sheet->headers())->toBe(['Client', 'BFC']);
});

it('reads a csv file with a txt extension', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\n", 'txt'));

    expect($sheet->headers())->toBe(['Client', 'BFC'])
        ->and($sheet->count())->toBe(1);
});

it('reads an excel file, with numbers as the text that was typed', function () {
    $sheet = Spreadsheet::open(workbook([
        ['Client mapping'],
        [],
        ['Client', 'BFC', 'Share'],
        ['Sony', 1234.0, 0.25],
        [null, null, null],
        ['Pepsi', 5678, 1.5],
    ]));

    expect($sheet->headers())->toBe(['Client', 'BFC', 'Share'])
        ->and($sheet->preview())->toBe([
            ['line' => 4, 'values' => ['Client' => 'Sony', 'BFC' => '1234', 'Share' => '0.25']],
            ['line' => 6, 'values' => ['Client' => 'Pepsi', 'BFC' => '5678', 'Share' => '1.5']],
        ]);
});

it('can read the rows more than one time', function () {
    $sheet = Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\nPepsi,5678\n"));

    expect($sheet->count())->toBe(2)
        ->and($sheet->count())->toBe(2)
        ->and($sheet->preview(1))->toHaveCount(1);
});

it('gives the path of the file', function () {
    $path = spreadsheet("Client,BFC\nSony,1234\n");

    expect(Spreadsheet::open($path)->path())->toBe($path);
});

it('does not open a file that is not there', function () {
    Spreadsheet::open('/tmp/nothing-here-'.uniqid().'.csv');
})->throws(UnreadableFileException::class);

it('does not open an ods file', function () {
    Spreadsheet::open(spreadsheet("Client,BFC\nSony,1234\n", 'ods'));
})->throws(UnreadableFileException::class);

it('does not open a file that has no headings', function () {
    Spreadsheet::open(spreadsheet("Just a note\n\nAnd another\n"));
})->throws(UnreadableFileException::class);

it('does not open a file that is not a spreadsheet', function () {
    Spreadsheet::open(spreadsheet('this is not a zip archive', 'xlsx'));
})->throws(UnreadableFileException::class);
