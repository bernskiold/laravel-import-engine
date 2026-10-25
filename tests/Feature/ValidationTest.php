<?php

use Bernskiold\LaravelImportEngine\Enums\ImportOutcome;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\Contact;
use Bernskiold\LaravelImportEngine\Tests\Fixtures\ContactImporter;

/**
 * The contact importer, with rules.
 */
function validatingImporter(): ContactImporter
{
    return new class extends ContactImporter
    {
        public function rules(): array
        {
            return [
                'email' => ['required', 'email'],
                'title' => ['nullable', 'in:'.implode(',', $this->option('titles', ['CMO', 'CEO']))],
            ];
        }

        public function messages(): array
        {
            return ['title.in' => 'The :attribute is not on the list.'];
        }
    };
}

it('records a row that does not obey the rules as failed, and does not import it', function () {
    $report = validatingImporter()->execute(spreadsheet("Name,Email,Title\nSony,not-an-email,CMO\nPepsi,pepsi@example.com,CMO\n"));

    $failed = $report->entries()->firstWhere('outcome', ImportOutcome::Failed);

    expect($report->countOf(ImportOutcome::Created))->toBe(1)
        ->and($failed->row)->toBe(2)
        ->and($failed->subject)->toBe('Sony')
        ->and($failed->notes)->toBe(['The Email field must be a valid email address.'])
        ->and(Contact::query()->pluck('email')->all())->toBe(['pepsi@example.com']);
});

it('reads a placeholder as no value for the rules', function () {
    $report = validatingImporter()->execute(spreadsheet("Name,Email,Title\nSony,sony@example.com,n/a\n"));

    expect($report->countOf(ImportOutcome::Created))->toBe(1);
});

it('uses the custom messages, the labels and the options', function () {
    $report = validatingImporter()->execute(
        spreadsheet("Name,Email,Title\nSony,sony@example.com,CFO\n"),
        options: ['titles' => ['CMO']],
    );

    expect($report->entries()->sole()->notes)->toBe(['The Job title is not on the list.']);
});

it('counts a row that does not obey the rules as read', function () {
    $report = validatingImporter()->execute(spreadsheet("Name,Email\nSony,x\nPepsi,y\n"));

    expect($report->rows())->toBe(2)
        ->and($report->countOf(ImportOutcome::Failed))->toBe(2);
});
