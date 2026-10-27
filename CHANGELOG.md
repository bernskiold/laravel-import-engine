# Changelog

All the important changes to `laravel-import-engine` are in this file.

## v0.1.0 - 2026-10-27

First release.

- `Importer` base class: columns, a column map that the engine can guess, a report entry for each row, a transaction for each row, and failures that do not stop the run.
- `SimpleImporter` base class for files with fixed headings.
- `Spreadsheet` reader for CSV and XLSX files. It finds the headings under a title row, it reads semicolon and tab files, and it keeps the line numbers of the file.
- `ImportRow` with tolerant accessors: `get()`, `values()`, `lines()`, `integer()`, `number()` and `boolean()`.
- `ImportReport` with the outcomes created, updated, unchanged, conflicted, skipped and failed, warnings for each entry, and tallies.
- `Import` model with a summary of the counts, the `withoutReport()` scope, a migration, the `StartImport`, `RunImport` and `PreviewImport` actions, and the `RunImportJob` job.
- `ImportStarted`, `ImportCompleted` and `ImportFailed` events.
- `Workbook` to download a template or an export as an Excel file.
- Support for openspout 4 and 5.
- Validation rules for each row, with `rules()`, `messages()` and `validationAttributes()`.
- The progress of a run on the record: `total_rows`, `processed_rows` and `progress()`.
- The text encoding of a CSV file: UTF-8, UTF-16 with a byte order mark, or the `csv_fallback_encoding` config.
- `ImportRow::date()` and `ImportRow::toArray()`. Excel date cells keep their time.
- Files with one column.
- `Testing\Spreadsheets` and `StartImport::fake()` for tests.
- Pruning of settled imports with the `prune_after_days` config.
- `RowException` and `ImportFileException` messages for the person. Other exception messages stay hidden, unless `show_exception_messages` is on.
- `RunImport` claims a pending record, so a record runs one time only.
- The `user_key_type` config, for users with UUID or ULID keys.
- Tests on SQLite, MySQL and Postgres.
