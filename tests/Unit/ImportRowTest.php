<?php

use Bernskiold\LaravelImportEngine\Support\ImportRow;

it('reads a placeholder as no value', function (string $cell) {
    $row = new ImportRow(line: 2, values: ['code' => $cell]);

    expect($row->get('code'))->toBeNull()
        ->and($row->has('code'))->toBeFalse()
        ->and($row->values('code'))->toBe([]);
})->with(['', '  ', '-', '—', 'n/a', 'N/A', 'TBC', 'null', '?']);

it('trims the value', function () {
    expect((new ImportRow(line: 2, values: ['name' => '  Sony  ']))->get('name'))->toBe('Sony');
});

it('gives null for a field that the row does not have', function () {
    expect((new ImportRow(line: 2))->get('name'))->toBeNull();
});

it('splits a cell on each separator that people use', function () {
    $row = new ImportRow(line: 2, values: ['code' => "1234, 1235; 1236 | 1237\n1238/1239"]);

    expect($row->values('code'))->toBe(['1234', '1235', '1236', '1237', '1238', '1239']);
});

it('removes a value that is two times in one cell', function () {
    expect((new ImportRow(line: 2, values: ['code' => '1234, 1234, TBC']))->values('code'))->toBe(['1234']);
});

it('splits names on line breaks only', function () {
    $row = new ImportRow(line: 2, values: ['account' => "Mars, Incorporated\r\nProcter & Gamble"]);

    expect($row->lines('account'))->toBe(['Mars, Incorporated', 'Procter & Gamble']);
});

it('reads a whole number as it was typed', function (string $cell, ?int $expected) {
    expect((new ImportRow(line: 2, values: ['rank' => $cell]))->integer('rank'))->toBe($expected);
})->with([
    ['4', 4],
    ['#4', 4],
    ['4.0', 4],
    ['4.5', null],
    ['top 10', null],
    ['', null],
]);

it('reads a number', function (string $cell, ?float $expected) {
    expect((new ImportRow(line: 2, values: ['share' => $cell]))->number('share'))->toBe($expected);
})->with([
    ['0.25', 0.25],
    ['#3', 3.0],
    ['-1.5', -1.5],
    ['1,000', null],
    ['n/a', null],
]);

it('reads a boolean', function (string $cell, ?bool $expected) {
    expect((new ImportRow(line: 2, values: ['active' => $cell]))->boolean('active'))->toBe($expected);
})->with([
    ['yes', true],
    ['Y', true],
    ['TRUE', true],
    ['1', true],
    ['x', true],
    ['no', false],
    ['N', false],
    ['false', false],
    ['0', false],
    ['maybe', null],
    ['', null],
]);

it('gives the default for a boolean without a value', function () {
    expect((new ImportRow(line: 2, values: ['active' => '']))->boolean('active', default: true))->toBeTrue()
        ->and((new ImportRow(line: 2, values: ['active' => 'maybe']))->boolean('active', default: false))->toBeFalse();
});

it('gives all the mapped values without the placeholders', function () {
    $row = new ImportRow(line: 2, values: ['name' => 'Sony', 'code' => 'n/a'], original: ['Client' => 'Sony', 'Code' => 'n/a']);

    expect($row->all())->toBe(['name' => 'Sony'])
        ->and($row->original())->toBe(['Client' => 'Sony', 'Code' => 'n/a']);
});
