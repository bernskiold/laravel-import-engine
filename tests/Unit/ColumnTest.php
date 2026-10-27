<?php

use Bernskiold\LaravelImportEngine\Support\Column;

it('uses the key when there is no label', function () {
    expect(Column::make('email')->getLabel())->toBe('email');
});

it('makes a heading in snake case from the label', function () {
    expect(Column::make('vat', 'VAT number')->getHeading())->toBe('vat_number')
        ->and(Column::make('duns', 'D-U-N-S identifier')->getHeading())->toBe('d_u_n_s_identifier');
});

it('uses a heading that is set', function () {
    expect(Column::make('name', 'Client name')->heading('Client')->getHeading())->toBe('Client');
});

it('keeps the settings', function () {
    $column = Column::make('email', 'Email')
        ->required()
        ->aliases('e-mail', 'mail')
        ->aliases('work email')
        ->description('The work email.');

    expect($column->toArray())->toBe([
        'key' => 'email',
        'label' => 'Email',
        'heading' => 'email',
        'description' => 'The work email.',
        'required' => true,
        'aliases' => ['e-mail', 'mail', 'work email'],
    ]);
});
