<?php

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Enums\ImportSeverity;
use Bernskiold\LaravelImportEngine\Support\ImportEntry;
use Bernskiold\LaravelImportEngine\Support\ImportReport;
use Bernskiold\LaravelImportEngine\Support\ImportRow;

it('counts each outcome and each severity', function () {
    $report = (new ImportReport)
        ->created(2, 'Sony')
        ->created(3, 'Pepsi')
        ->updated(4, 'Mars')
        ->unchanged(5, 'Shell')
        ->conflicted(6, 'Nestlé', ['Already claimed.'])
        ->skipped(7)
        ->failed(8, 'Unilever', ['Broken.']);

    expect($report->rows())->toBe(7)
        ->and($report->countOf(ImportOutcome::Created))->toBe(2)
        ->and($report->countOfSeverity(ImportSeverity::Success))->toBe(4)
        ->and($report->countOfSeverity(ImportSeverity::Warning))->toBe(2)
        ->and($report->countOfSeverity(ImportSeverity::Error))->toBe(1)
        ->and($report->needingAttention())->toBe(3)
        ->and($report->changedNothing())->toBeFalse();
});

it('takes the line from a row', function () {
    $report = (new ImportReport)->created(new ImportRow(line: 12), 'Sony');

    expect($report->entries()->first()->row)->toBe(12);
});

it('knows when a run changed nothing', function () {
    $report = (new ImportReport)->unchanged(2, 'Sony')->unchanged(3, 'Pepsi');

    expect($report->changedNothing())->toBeTrue();
});

it('counts the entries with warnings', function () {
    $report = (new ImportReport)
        ->created(2, 'Sony', warnings: ['No division found.'])
        ->created(3, 'Pepsi');

    expect($report->countWithWarnings())->toBe(1)
        ->and($report->entries()->first()->hasWarnings())->toBeTrue();
});

it('adds up the tallies', function () {
    $report = (new ImportReport)->tally('links')->tally('links', 2)->tally('other');

    expect($report->tallies())->toBe(['links' => 3, 'other' => 1]);
});

it('merges another report', function () {
    $report = (new ImportReport)->created(2)->tally('links');
    $report->merge((new ImportReport)->updated(3)->tally('links', 2));

    expect($report->rows())->toBe(2)
        ->and($report->tallies())->toBe(['links' => 3]);
});

it('stores and reads back the same report', function () {
    $report = (new ImportReport)
        ->created(2, 'Sony', ['Made.'], ['Check the title.'])
        ->failed(null, 'Pepsi', ['Broken.'])
        ->tally('links', 4);

    $stored = json_decode(json_encode($report->toArray()), true);

    expect(ImportReport::fromArray($stored)->toArray())->toBe($report->toArray());
});

it('reads an unknown outcome as failed', function () {
    $entry = ImportEntry::fromArray(['row' => '4', 'outcome' => 'renamed', 'notes' => [1]]);

    expect($entry->outcome)->toBe(ImportOutcome::Failed)
        ->and($entry->row)->toBe(4)
        ->and($entry->notes)->toBe(['1'])
        ->and($entry->warnings)->toBe([]);
});

it('reads an empty report', function () {
    $report = ImportReport::fromArray([]);

    expect($report->rows())->toBe(0)
        ->and($report->tallies())->toBe([]);
});
