# Changelog

## Unreleased

- Added the read-only `mytree-index-view` TUI for browsing versioned acquisition manifests and JSONL records offline, including search, type/year filters, record details, provenance and scan locators.

## 0.5.0

- **Breaking:** replaced Geneteka's multi-argument `acquire(...)` API with immutable fluent `acquisition()` builder representing one provider-form query and one `RecordType`.
- Geneteka fluent query supports date ranges, primary/secondary person filters, exact matching, excluding parent fields, and provider-specific `formParameter(...)` escape hatch.
- Geneteka cache/checkpoint keys now use a deterministic fingerprint of the complete query configuration.
- Pagination prefers `recordsFiltered` when provider-side filters are active.
- Added Geneteka availability discovery with record-type-specific `rid` values and non-continuous `YearRange` coverage.
- Added `--availability` CLI diagnostics and switched Geneteka CLI acquisition to singular `--type` plus optional search filters.
- Added shared `RecordType` enum (`birth`, `marriage`, `death`, `parish_census`).
- Geneteka maps canonical record types to its `bdm` codes internally.
- Migrated the test suite from the custom `tests/run.php` runner to PHPUnit with separate unit and integration tests.

## 0.2.0

- Added parish discovery mode for Geneteka and Metryki-Wołyń.
- Added common `AvailableParish` model (`mytree.available-parish.v1`).
- Geneteka: region-scoped parish discovery and optional all-region discovery.
- Geneteka: fallback discovery from the `parishes` member of `getAct.php` JSON.
- Metryki-Wołyń: discovery from the public `Zawartość` table with entry/index coverage metadata.
- CLI discovery output formats: table, JSON, JSONL; optional `--save` and `--refresh`.
- Added tests for discovery parsers and provider-level discovery.

## 0.1.0

- Initial Geneteka and Metryki-Wołyń acquisition providers.
