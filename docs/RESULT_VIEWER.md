# Acquisition Result Viewer

## Purpose

`mytree-index-view` is a read-only terminal user interface for inspecting an acquisition directory produced by the standalone Index Providers CLI.

The viewer is deliberately separate from acquisition. It performs no HTTP requests, does not run provider parsers against preserved raw responses, and does not modify `manifest.json`, `records.jsonl`, checkpoint files, or raw response files.

## Supported input

The initial viewer supports:

```text
mytree.index-acquisition-manifest.v1
mytree.external-index-record.v1
```

A run directory normally has this shape:

```text
<run>/
├── manifest.json
├── records.jsonl
├── state/
│   └── checkpoints.json
└── raw/
    └── ...
```

Only `manifest.json` and the manifest-declared `records_file` are required for the record browser. Raw provider responses are not parsed to build the list.

## Launch

From a source checkout:

```bash
php bin/mytree-index-view var/walenty-wisniewski-1863-1864
```

When installed through Composer, `bin/mytree-index-view` is exposed as a package binary.

The command requires an interactive TTY. The initial terminal adapter uses ANSI escape sequences and `stty`, so it is intended for Unix-like terminals (including typical Linux/macOS shells and compatible terminal environments). It reports a clear error when it cannot enter interactive terminal mode.

## Views

The list view shows:

- provider and run statistics from `manifest.json`,
- acquisition time range and compact query configuration,
- record type,
- year,
- a provider-neutral primary-person projection,
- place/parish,
- compact related-person information when available,
- scan-locator availability,
- provider record id on wider terminals.

The details view keeps the serialized record structure visible in explicit sections:

```text
IDENTITY
LOCATORS
FIELDS
RAW PROVIDER VALUES
PROVENANCE
REPRESENTATION
```

Unknown or provider-specific nested values are rendered recursively instead of being dropped.

## Search and filters

Press `/` to start incremental free-text search. Search is case-insensitive for Polish text and searches record identity plus indexed/provider values from `fields` and `raw`. Acquisition query parameters stored in provenance are intentionally excluded so that a searched surname does not automatically match every result merely because it was part of the original query.

Press `f` to edit filters. The filter syntax is:

```text
type=birth
year=1863
year=1863-1864
type=birth year=1863-1864
```

Press `c` to clear the current search and filters.

## Key bindings

```text
↑/↓ or j/k   move selection / scroll details
Enter        open selected record details
Esc          return to the list or cancel input
/            edit incremental free-text search
f            edit type/year filters
c            clear search and filters
?            show in-application help
q            quit
```

Backspace also returns from a secondary view when not editing text.

## Validation and diagnostics

The loader rejects:

- a missing or unreadable run directory,
- a missing or malformed `manifest.json`,
- an unsupported manifest schema,
- an absolute `records_file` or a records path escaping the run directory,
- a missing/unreadable records file,
- malformed JSONL with the failing line number,
- unsupported record schemas,
- records missing required identity/structured members.

## Performance model

The viewer decodes the manifest and JSONL records once at startup and keeps a compact display/search projection for each record. Navigation and filtering do not re-read or re-decode the JSONL file.

Memory use therefore grows with the number and size of records in a run. This is appropriate for ordinary personal acquisition runs; a future viewer for very large/multi-run datasets may need an indexed on-disk model rather than changing the provider output contract.
