# Geneteka — queries, availability, and responsibility boundaries

## Purpose

`GenetekaProvider` faithfully models the capabilities of the Geneteka provider. It does not plan MyTree research.

The library layer is responsible for:

```text
provider form/query
→ HTTP
→ pagination
→ raw cache/checkpoint
→ ExternalIndexRecord
```

A higher layer such as Laravel/MyTree may later be responsible for:

```text
availability
→ partition work into ranges
→ choose providers
→ order queries
→ acquisition budget/strategy
```

## One query equals one RecordType

Geneteka exposes separate form states for:

```text
birth
marriage
death
```

`GenetekaAcquisition` therefore represents exactly one `RecordType`.

```php
$provider
    ->acquisition()
    ->region('06mp')
    ->parish('4812', 'Imbramowice')
    ->recordType(RecordType::Birth)
    ->years(1853, 1860)
    ->acquire($writer);
```

`banns`, `other`, and `parish_census` are not accepted Geneteka query types and are rejected before network I/O.

## Fluent API

The builder is immutable: every configuration method returns a new query object.

Verified convenience methods map to provider form fields as follows:

```text
person()          → search_lastname / search_name
secondPerson()    → search_lastname2 / search_name2
fromYear()        → from_date
toYear()          → to_date
years()           → from_date + to_date
exact()           → exac=1
excludeParents()  → parents=1
```

Do not guess the names or semantics of unverified provider form options. `formParameter()` is the controlled escape hatch for adding such fields incrementally; after their semantics are confirmed, a typed fluent method may be introduced.

Transport parameters controlled by the provider are reserved:

```text
bdm
w
rid
length
start
```

## Cache and checkpoints

A query fingerprint depends on:

```text
region
provider parish id (rid)
record type
all configured provider form parameters
page size
```

Different queries therefore do not accidentally share raw pages or completion checkpoints.

When returned by the API, `recordsFiltered` is used to calculate result-page count; `recordsTotal` remains a fallback.

## Availability discovery

Geneteka may publish discontinuous coverage, for example:

```text
1645
1654
1656
1658-1862
1868-1907
```

These ranges must not be flattened to `1645-1907`, because that would erase the published gaps.

The provider reads availability metadata from the public Geneteka HTML interface. It does not assume a separate stable coverage API.

`discoverAvailability()` returns `GenetekaRecordAvailability[]` containing:

```text
RecordType
providerParishId (the rid appropriate for that type)
YearRange[]
sourceUrl
```

This is provider metadata, not an acquisition plan.

## RID may differ by record type

Geneteka's Birth/Marriage/Death tabs may use different `rid` values for the same logical parish. A higher layer must therefore not assume:

```text
one parish = one rid for all record types
```

Availability discovery preserves identifiers independently for each `RecordType`.

## Higher-level unification

This repository does not introduce a generic Acquisition Manager. Provider-specific fluent APIs may expose the verified surface of each provider.

A higher orchestration layer may later translate a shared research intention such as:

```text
find the birth record of person X in interval Y
```

into concrete Geneteka, Metryki-Wołyń, BASIA, or future-provider operations.
