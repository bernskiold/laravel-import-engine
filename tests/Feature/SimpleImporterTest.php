<?php

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Exceptions\MissingColumnsException;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\Sector;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\SectorImporter;

it('reads the fixed headings without a map', function () {
    $report = app(SectorImporter::class)->execute(spreadsheet("name,is_active\nAutomotive,yes\nBanking,no\n"));

    expect($report->countOf(ImportOutcome::Created))->toBe(2)
        ->and(Sector::query()->where('name', 'Banking')->sole()->is_active)->toBeFalse();
});

it('agrees with headings in a different case or with spaces', function () {
    app(SectorImporter::class)->execute(spreadsheet("Name,Is Active\nAutomotive,no\n"));

    expect(Sector::query()->sole()->is_active)->toBeFalse();
});

it('does not need the optional headings', function () {
    app(SectorImporter::class)->execute(spreadsheet("name,notes\nAutomotive,x\n"));

    expect(Sector::query()->sole()->is_active)->toBeTrue();
});

it('stops when a required heading is missing', function () {
    app(SectorImporter::class)->execute(spreadsheet("title,notes\nAutomotive,x\n"));
})->throws(MissingColumnsException::class, 'These required columns are missing: name.');

it('accepts a map', function () {
    app(SectorImporter::class)->execute(spreadsheet("Sector,Active\nAutomotive,no\n"), ['Sector' => 'name', 'Active' => 'is_active']);

    expect(Sector::query()->sole()->is_active)->toBeFalse();
});

it('reports an unchanged row on a second run', function () {
    $path = spreadsheet("name,is_active\nAutomotive,yes\n");

    app(SectorImporter::class)->execute($path);

    expect(app(SectorImporter::class)->execute($path)->changedNothing())->toBeTrue();
});

it('makes a template from the headings', function () {
    expect(app(SectorImporter::class)->template())->toBe(['name', 'is_active']);
});
