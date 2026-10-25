# Changelog

All the important changes to `laravel-import-engine` are in this file.

## Unreleased

- First release.
- `Importer` base class: columns, a column map that the engine can guess, a report entry for each row, a transaction for each row, and failures that do not stop the run.
- `SimpleImporter` base class for files with fixed headings.
- `Spreadsheet` reader for CSV and XLSX files. It finds the headings under a title row, it reads semicolon and tab files, and it keeps the line numbers of the file.
- `ImportRow` with tolerant accessors: `get()`, `values()`, `lines()`, `integer()`, `number()` and `boolean()`.
- `ImportReport` with the outcomes created, updated, unchanged, conflicted, skipped and failed, warnings for each entry, and tallies.
- `Import` model with a summary of the counts, the `withoutReport()` scope, a migration, the `StartImport`, `RunImport` and `PreviewImport` actions, and the `RunImportJob` job.
- `ImportStarted`, `ImportCompleted` and `ImportFailed` events.
- `Workbook` to download a template or an export as an Excel file.
- Support for openspout 4 and 5.
