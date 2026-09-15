# MyTree Index Providers

Standalone PHP package for acquiring genealogical index data from:

- **Geneteka** — paginated JSON from the internal `getAct.php` endpoint,
- **Metryki-Wołyń** — HTML search results acquired parish-by-parish and year-by-year,
- **BASIA** — bounded, low-rate search through the public advanced-search form plus indexed-catalog discovery.

The package is framework-independent and does not depend on Laravel. Providers accept a standard PSR-18 HTTP client, so MyTree or another host application can inject its own implementation without adapting to a MyTree-specific HTTP interface. The standalone CLI uses a default Guzzle 7 composition.

## Requirements

- PHP 8.2+
- Composer 2
- PHP DOM extension (`ext-dom`)
- dependencies from `composer.json` (`guzzlehttp/guzzle` and PSR-18/PSR-7/PSR-17 contracts)
- no Laravel dependency

Install dependencies before using the library or CLI:

```bash
composer install
```

## HTTP and integration boundary

The public HTTP-client boundary is `Psr\Http\Client\ClientInterface`. Requests and responses use PSR-7, and providers may also receive a custom `Psr\Http\Message\RequestFactoryInterface` and, when a request body is needed, a `Psr\Http\Message\StreamFactoryInterface`.

The default standalone client can be created with:

```php
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;

$http = DefaultHttpClientFactory::create(
    timeoutSeconds: 60,
    maxAttempts: 3,
);
```

`NativeHttpClient` remains temporarily as a deprecated compatibility shim. It implements PSR-18 and delegates to the default Guzzle-based stack.

A host application may inject any compatible PSR-18 client and, optionally, its own PSR-17 factories:

```php
$provider = new GenetekaProvider(
    $myPsr18Client,
    $checkpointStore,
    $rawResponseStore,
    requestFactory: $myPsr17RequestFactory,
);
```

The default composition retries transport failures, HTTP `429`, and `5xx` responses. Redirects for `GET`/`HEAD` are handled explicitly. Retry does not include `POST` by default; a provider performing a replayable read-only POST must opt in deliberately. The BASIA CLI does so because BASIA search is a read-only POST with a reproducible body.

Rate limiting remains a provider responsibility. BASIA defaults to a minimum interval of 5000 ms and a 200-second timeout; Geneteka and Metryki-Wołyń keep the existing defaults of 2000 ms and 60 seconds.

See [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md) for host-application composition.

## Quick start

### Geneteka — Imbramowice, Małopolskie

```bash
php bin/mytree-index geneteka \
  --region=06mp \
  --parish-id=4812 \
  --parish=Imbramowice \
  --type=birth \
  --from=1853 \
  --to=1860 \
  --output=var/imbramowice
```

The input type is MyTree's canonical `RecordType`, independent of provider-specific transport codes:

- `birth`,
- `marriage`,
- `death`.

One Geneteka acquisition represents one provider form state and one record type. Combining several record types, partitioning work into intervals, and scheduling subsequent queries belong to a higher orchestration layer.

### Geneteka fluent API

A query is built as an immutable configuration rather than by extending the `acquire(...)` argument list:

```php
$stats = $provider
    ->acquisition()
    ->region('06mp')
    ->parish('4812', 'Imbramowice')
    ->recordType(RecordType::Birth)
    ->person('Gajda', 'Józef')
    ->years(1853, 1860)
    ->exact()
    ->acquire($writer);
```

Verified form fields have typed convenience methods such as `person`, `secondPerson`, `years`, `exact`, and `excludeParents`. Provider-specific fields that do not yet have a dedicated method can be added through the controlled escape hatch:

```php
$query = $query->formParameter('provider_field_name', 'value');
```

Transport parameters `bdm`, `w`, `rid`, `length`, and `start` are reserved and cannot be overridden through `formParameter()`.

### Geneteka availability and per-type identifiers

The Geneteka UI publishes year coverage that may be discontinuous, and the Birth/Marriage/Death links for one logical parish may use different `rid` values. The provider exposes this metadata separately from acquisition:

```php
$availability = $provider->discoverAvailability(
    region: '10pl',
    anchorType: RecordType::Birth,
    anchorParishId: '4257',
);
```

Each `GenetekaRecordAvailability` contains its `recordType`, the corresponding `providerParishId`, and `YearRange[]`. The package does not automatically turn those ranges into an acquisition plan; a later Acquisition Manager or Laravel layer may do so.

Diagnostic CLI:

```bash
php bin/mytree-index geneteka --availability \
  --region=10pl --parish-id=4257 --type=birth --format=json
```

See [docs/GENETEKA_ACQUISITION.md](docs/GENETEKA_ACQUISITION.md).

### BASIA — bounded search

BASIA is intentionally not supported as a crawler or full-database mirror. One provider operation represents one deliberately bounded state of the public advanced-search form:

```bash
php bin/mytree-index basia \
  --surname=Kowalski \
  --name=Jan \
  --from=1880 \
  --to=1900 \
  --type=birth \
  --output=var/basia-kowalski
```

At least one of `--surname`, `--name`, or `--place` is required. Supported canonical types are:

- `birth`,
- `marriage`,
- `death`,
- `banns`,
- `other`.

`banns` is distinct from `marriage`. `other` means only the provider's explicit other/uncategorized category. A future BASIA category without an accepted canonical mapping is preserved as `provider:basia:<token>` rather than silently becoming `other`.

Additional CLI filters include `--place`, `--distance-km`, `--similarity`, `--sex`, `--relation`, and `--unit-type`. BASIA form codes remain private to the provider adapter; callers use canonical values only.

Fluent API:

```php
$stats = $basia
    ->search()
    ->person('Kowalski', 'Jan')
    ->years(1880, 1900)
    ->place('Poznań', 10)
    ->recordType(RecordType::Birth)
    ->similarity(70)
    ->acquire($writer);
```

BASIA can be slow for broad queries. HTTP `200` is not automatically treated as a successful search response: the parser requires the upstream search-complete marker. A response without that marker is not promoted to the reusable raw cache and does not create a completion checkpoint. Narrow the query deliberately and retry. A complete search with zero results is a valid success.

A scan URL returned by BASIA is preserved as a locator/acquisition lead. This Index Provider does not download the scan.

See [docs/BASIA_ACQUISITION.md](docs/BASIA_ACQUISITION.md).

### BASIA — indexed catalog

The BASIA indexed-content catalog (`content-all.php?lang=pl`) is broader than a parish list. One locality may expose, for example, a Catholic parish, a Protestant parish, a civil registry office, and other units at the same time. Catalog discovery therefore uses the additive `IndexCatalogUnit` contract instead of `AvailableParish`.

CLI:

```bash
php bin/mytree-index basia --list-catalog
php bin/mytree-index basia --list-catalog --format=json
php bin/mytree-index basia --list-catalog --format=jsonl
```

PHP API:

```php
$units = $basia->listCatalogUnits();
$units = $basia->listCatalogUnits(refresh: true);
```

`BasiaProvider` implements the optional `IndexCatalogDiscoveryInterface`. Each unit serializes as `mytree.index-catalog-unit.v1` and keeps locality/place separate from the indexed records-holding unit. Initial mappings are:

```text
Parafia katolicka       -> parish / roman_catholic
Parafia ewangelicka     -> parish / evangelical
Urząd Stanu Cywilnego   -> civil_registry
provider-declared other -> other
```

The Polish labels above are source values published by BASIA and are intentionally preserved. An unknown future unit type remains `provider:basia:<token>`. An entry with no unit label is preserved as `provider:basia:unlabeled`. The provider does not manufacture parish semantics for non-parish units.

Availability stores `YearRange[]` per record type so gaps remain explicit. The catalog also preserves raw labels, county, locality-wide entry totals, indexers, and raw-response provenance.

Catalog cache validation is structural: every parsed locality must contain the expected BASIA locality-completion metadata (`Razem wpisów` and `Indeksujący`) and parsing must succeed before the response is promoted to reusable cache. BASIA does not expose a known page-level completion marker analogous to bounded search's `Czas wyszukiwania`, so the package does not claim to prove global completeness of an otherwise structurally valid provider snapshot.

## Metryki-Wołyń — Szumsk

```bash
php bin/mytree-index wolyn \
  --parish=Szumsk \
  --from=1731 \
  --to=1943 \
  --output=var/szumsk
```

Metryki-Wołyń acquisition proceeds **year by year**. Known sections map to:

- `birth`,
- `marriage`,
- `death`,
- `parish_census`.

A future/unrecognized section is not discarded. Each row is emitted with a deterministic provider-qualified `record_type` such as `provider:wolyn-metryki:<token>`, while the exact section title, headers, cells, cell HTML, links, and request provenance are preserved. The provider does not guess column semantics or an event year for an unknown section.

## Discovery capabilities

The package exposes two deliberately separate discovery contracts.

### Legacy parish discovery

Geneteka and Metryki-Wołyń retain `AvailableParish` / `mytree.available-parish.v1` and the `--list-parishes` CLI mode. Their public return type is not changed merely because a more general catalog contract now exists.

#### Geneteka — one region

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp
```

For Geneteka, `provider_parish_id` is the provider `rid`, for example:

```text
REGION  ID    PARISH
------  ----  -----------
06mp    4812  Imbramowice
```

The list is read from the current Geneteka search form. If the form structure changes, the provider has a fallback using the `parishes` field returned by `getAct.php`.

All regions can also be discovered:

```bash
php bin/mytree-index geneteka --list-parishes --all-regions
```

This performs several requests, one per region, so `--delay-ms` still applies. Prefer discovery for a specific region when that is sufficient.

#### Metryki-Wołyń

```bash
php bin/mytree-index wolyn --list-parishes
```

The provider uses the portal's `Zawartość` page. In addition to the parish name it retains the portal's published ranges for entries and complete indexes covering births, marriages, deaths, and parish censuses.

Example table view:

```text
PARISH  BIRTHS     MARRIAGES  DEATHS     CENSUS
------  ---------  ---------  ---------  ------
Szumsk  1731-1926  1739-1943  1741-1939  1857
```

See [docs/PARISH_DISCOVERY.md](docs/PARISH_DISCOVERY.md).

### Generalized catalog discovery

The optional `IndexCatalogDiscoveryInterface` returns `IndexCatalogUnit[]`. BASIA implements this capability in P1. `AvailableParish` is neither removed nor changed.

Representative BASIA result:

```json
{
  "schema": "mytree.index-catalog-unit.v1",
  "provider": "basia",
  "catalog_unit_key": "basia:...",
  "provider_unit_id": null,
  "locality": {
    "provider_place_id": null,
    "name": "Blizanów",
    "county": "kaliski",
    "region_code": null,
    "region_name": null
  },
  "unit_name": "Parafia katolicka",
  "unit_kind": "parish",
  "denomination": "roman_catholic",
  "availability": [
    {
      "record_type": "birth",
      "year_ranges": [
        {"from": 1819, "to": 1819},
        {"from": 1821, "to": 1822}
      ],
      "raw_type_label": "chrzty",
      "records_count": null
    }
  ],
  "raw": {},
  "provenance": {},
  "metadata": {}
}
```

## Discovery output formats and cache

Discovery output defaults to a table. JSON and JSONL are also supported:

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp --format=json
php bin/mytree-index wolyn --list-parishes --format=jsonl
php bin/mytree-index basia --list-catalog --format=json
```

Output can be saved to a file:

```bash
php bin/mytree-index wolyn --list-parishes --format=json --save=parishes-wolyn.json
php bin/mytree-index basia --list-catalog --format=json --save=basia-catalog.json
```

Representative legacy parish-discovery record:

```json
{
  "schema": "mytree.available-parish.v1",
  "provider": "geneteka",
  "provider_parish_id": "4812",
  "name": "Imbramowice",
  "region_code": "06mp",
  "region_name": "Małopolskie",
  "metadata": {}
}
```

For Metryki-Wołyń, `provider_parish_id` is `null` because the search input currently identifies a parish by its textual name. Published coverage remains in `metadata.wpisy` and `metadata.indeksy`; those keys preserve provider wording.

Discovery uses a separate raw-page cache. The default directory is `var/discovery-cache`; use `--output=DIR` to select another location and `--refresh` to bypass the cached provider snapshot.

BASIA stores a successfully parsed catalog at `raw/basia/catalog_pl.html` together with metadata. Structural parse failures are not promoted to a successful reusable cache entry.

## Rate limiting

Geneteka and Metryki-Wołyń default to at least 2000 ms between network requests. BASIA uses a more conservative default of 5000 ms:

```bash
--delay-ms=5000
```

Reducing these delays is discouraged. The package is intended for controlled personal research. Before larger acquisitions, verify that the intended use is consistent with the rules and policies of the upstream service.

## Resume and restart

The CLI checkpoints complete acquisition work units:

- Geneteka — result page,
- Metryki-Wołyń — year,
- BASIA bounded search — complete bounded query.

Re-running the same acquisition with the same `--output` resumes from local state.

Geneteka cache/checkpoints are partitioned by a deterministic fingerprint of the full query configuration: region, `rid`, record type, form parameters, and page size. BASIA bounded search uses an analogous fingerprint of its canonical search configuration and only caches a response after search completeness has been confirmed.

BASIA catalog discovery is a separate provider snapshot. It uses raw cache plus `--refresh` and does not create a person-record acquisition checkpoint.

Raw responses are retained under `raw/`. If an acquisition response was retrieved but processing stopped before checkpoint completion, a later run can reuse the local raw response when that provider workflow considers it reusable.

`records.jsonl` deduplicates by `provider_record_id`, preventing ordinary resume from creating duplicate semantic rows.

### Full restart

```bash
... --restart
```

For record acquisition, `--restart`:

- removes `records.jsonl`,
- removes checkpoints,
- bypasses existing cache during the run,
- replaces corresponding raw responses with newly retrieved responses.

For discovery, prefer the explicit `--refresh` mechanism.

## Output layout

Geneteka example:

```text
var/imbramowice/
├── manifest.json
├── records.jsonl
├── state/
│   └── checkpoints.json
└── raw/
    └── geneteka/
        ├── query_<fingerprint>_0.json
        ├── query_<fingerprint>_0.json.meta.json
        └── ...
```

Metryki-Wołyń raw responses:

```text
raw/wolyn-metryki/
├── szumsk_1731.html
├── szumsk_1731.html.meta.json
└── ...
```

BASIA raw responses:

```text
raw/basia/
├── query_<fingerprint>.html
├── query_<fingerprint>.html.meta.json
├── catalog_pl.html
└── catalog_pl.html.meta.json
```

## Result viewer (TUI)

Existing acquisition output can be inspected with the separate read-only/offline viewer:

```bash
php bin/mytree-index-view var/walenty-wisniewski-1863-1864
```

The viewer supports `mytree.index-acquisition-manifest.v1` plus `mytree.external-index-record.v1`. It reads `manifest.json` and `records.jsonl`; it does not contact providers, mutate checkpoints, or parse preserved raw HTML/JSON to reconstruct records.

Main keys:

```text
↑/↓ or j/k   move selection / scroll details
Enter        open record details
/            incremental free-text search
f            filters: type=<type> year=<YYYY|YYYY-YYYY>
c            clear search and filters
?            help
q            quit
```

The initial terminal adapter uses ANSI + `stty` and therefore targets Unix-like interactive terminals. See [docs/RESULT_VIEWER.md](docs/RESULT_VIEWER.md) for validation rules, details-view behavior, search semantics, and current limitations.

## `ExternalIndexRecord`

Every acquisition JSONL line uses the stable `mytree.external-index-record.v1` contract:

```json
{
  "schema": "mytree.external-index-record.v1",
  "provider": "wolyn-metryki",
  "provider_record_id": "...",
  "record_type": "birth",
  "parish": "Szumsk",
  "year": 1835,
  "fields": {},
  "raw": {},
  "provenance": {}
}
```

`fields` provides a convenient structured projection, while `raw` retains provider values. The package does not resolve uncertain values such as `20?`, surname variants, or free-text note semantics. Provider/indexer descriptive values use `ValueRepresentation::indexerRendering(...)` so downstream code does not mistake them for guaranteed wording from the historical document.

See [docs/VALUE_REPRESENTATION.md](docs/VALUE_REPRESENTATION.md).

## Provenance

Acquisition records retain, as applicable:

- request URL or stable provider permalink,
- retrieval time,
- raw-response path,
- raw-response SHA-256,
- provider-specific query configuration/fingerprint,
- page/year/result/row index,
- parser version where relevant.

`IndexCatalogUnit` also retains the catalog URL, retrieval time, raw-response path/hash, parser version, and catalog-item index. `catalog_unit_key` is a deterministic provider-local discovery key, not a MyTree `SourceId`.

This allows a later MyTree importer to preserve the origin of data without collapsing the boundaries between discovery, external index acquisition, and historical source identity.

## Tests

Tests use PHPUnit and are designed to run offline.

```bash
composer install
composer test
```

Direct PHPUnit invocation:

```bash
vendor/bin/phpunit
```

Normal tests must not depend on third-party service availability. BASIA uses static fixtures for bounded search and separate catalog fixtures covering multiple units under one locality, discontinuous ranges, explicit/unknown categories, and structurally incomplete responses.

## Laravel / MyTree integration

See [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md) and [docs/BASIA_ACQUISITION.md](docs/BASIA_ACQUISITION.md).
