# BASIA acquisition and catalog discovery

BASIA support in `mytree/index-providers` deliberately exposes two separate capabilities:

1. **bounded, low-rate record search** producing `mytree.external-index-record.v1`,
2. **indexed-content catalog discovery** producing `mytree.index-catalog-unit.v1`.

Neither capability is a bulk crawler or mirror of the BASIA corpus.

## Provider contract

The provider key is `basia`. `BasiaProvider` exposes immutable bounded search through `search()` and implements the optional `IndexCatalogDiscoveryInterface` capability through `listCatalogUnits()`.

### Bounded-search record types

Supported canonical record types are:

- `birth`,
- `marriage`,
- `death`,
- `banns`,
- `other`.

`banns` is distinct from `marriage`. `other` is used only when BASIA explicitly labels the record as its provider-side “other” category. If a future BASIA category is observed and is not mapped, the record type is emitted as an opaque provider-qualified value such as `provider:basia:<token>` rather than being silently coerced to `other`.

The BASIA search-form codes (`a`, `b`, `c`, `d`, `z`) remain private implementation details of the adapter. Callers use only canonical `RecordType` values.

## Bounded-search CLI

A bounded search can be executed through the common CLI:

```bash
php bin/mytree-index basia \
  --surname=Kowalski \
  --name=Jan \
  --from=1880 \
  --to=1900 \
  --type=birth \
  --output=var/basia-kowalski
```

At least one of `--surname`, `--name`, or `--place` is required. Optional filters include:

```text
--place=...
--distance-km=10
--similarity=60
--sex=any|male|female
--relation=any|parent_or_spouse|child|other
--unit-type=any|usc|catholic|evangelical|other
--type=birth|marriage|death|banns|other
```

The CLI defaults for BASIA are deliberately conservative:

- one synchronous request in flight,
- minimum interval: `5000 ms`,
- HTTP timeout: `200 s`,
- transport retry for replayable read-only `POST` requests on transport failures, HTTP `429`, and `5xx` responses.

The delay and timeout can be overridden with `--delay-ms` and `--timeout`, but broad searches are discouraged.

## Bounded-search PHP API

```php
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Provider\BasiaProvider;

$search = $basia
    ->search()
    ->person('Kowalski', 'Jan')
    ->years(1880, 1900)
    ->place('Poznań', 10)
    ->recordType(RecordType::Birth)
    ->similarity(70);

$stats = $search->acquire($writer);
```

The query is immutable. Its deterministic fingerprint is based on the canonical search configuration and is used to isolate raw-response cache and checkpoints.

## Catalog discovery

BASIA publishes an indexed-content catalogue at `content-all.php?lang=pl`. The catalogue is broader than a parish list: one locality may contain an ecclesiastical parish, another denomination, a civil registry office (`USC`) and other provider-defined units.

For that reason BASIA does **not** project this data into the legacy `AvailableParish` contract. Instead it exposes the generalized optional capability:

```php
use MyTree\IndexProviders\Contracts\IndexCatalogDiscoveryInterface;

/** @var IndexCatalogDiscoveryInterface $basia */
$units = $basia->listCatalogUnits();
```

Each result is an `IndexCatalogUnit` serialized as:

```text
mytree.index-catalog-unit.v1
```

The contract keeps locality separate from the records-holding/indexed unit. Important fields include:

```text
provider
catalog_unit_key
provider_unit_id
locality
unit_name
unit_kind
denomination
availability[]
raw
provenance
metadata
```

The initial BASIA unit mappings are:

```text
Parafia katolicka       -> unit_kind=parish, denomination=roman_catholic
Parafia ewangelicka     -> unit_kind=parish, denomination=evangelical
Urząd Stanu Cywilnego   -> unit_kind=civil_registry
provider-declared other -> unit_kind=other
```

A future unmapped unit type is emitted as `provider:basia:<token>`. It is never silently converted to `other`. If BASIA publishes an entry without a unit label, it is preserved as `provider:basia:unlabeled` rather than being invented as a parish.

Availability is represented independently per record type using lists of `YearRange`, so discontinuous coverage such as `1818-1820, 1822-1823, 1825-1829` remains discontinuous instead of being collapsed to a misleading minimum/maximum interval.

Raw provider unit/type labels, locality/county wording, locality-wide total counts and indexer information are retained together with raw-response provenance.

### Catalog CLI

Use the catalog-oriented discovery flag rather than `--list-parishes`:

```bash
php bin/mytree-index basia --list-catalog --format=table
php bin/mytree-index basia --list-catalog --format=json
php bin/mytree-index basia --list-catalog --format=jsonl
```

The normal discovery cache directory is `var/discovery-cache` unless `--output=DIR` is supplied. Use `--refresh` to bypass the cached catalogue response and fetch it again.

`--list-parishes` remains the legacy parish-oriented discovery surface for Geneteka and Metryki-Wołyń. It is intentionally not used for BASIA.

## HTTP boundary

`BasiaProvider` accepts a standard `Psr\Http\Client\ClientInterface` and optional PSR-17 request/stream factories.

Bounded search submits the BASIA advanced search as `POST /` with `application/x-www-form-urlencoded` data. Catalog discovery uses a read-only `GET` to `content-all.php?lang=pl` through the same PSR-18 boundary.

A standalone composition with BASIA-safe defaults can be built as follows:

```php
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;
use MyTree\IndexProviders\Provider\BasiaProvider;

$http = DefaultHttpClientFactory::create(
    timeoutSeconds: BasiaProvider::DEFAULT_TIMEOUT_SECONDS,
    retryableMethods: ['GET', 'HEAD', 'POST'],
);
```

Retry is a transport concern. A structurally incomplete successful response is **not** blindly retried by the generic retry layer.

## Search completeness and broad-query limitation

BASIA searches can take a long time. A query that is too broad can return HTTP `200` with a truncated/unusable page. The search parser therefore requires the provider’s search-complete marker (`Czas wyszukiwania`) before treating a response as complete.

The search provider distinguishes:

- complete result page with records — success,
- complete result page with zero records — valid empty success,
- HTTP `200` without the completion marker — `BasiaIncompleteResponseException`,
- structurally malformed/unexpected result boxes — parsing failure.

Incomplete or malformed search responses are not written as successful raw-cache entries and do not create a completion checkpoint. The caller should narrow the search and retry deliberately.

## Catalog completeness and cache behavior

Catalog discovery parses and structurally validates the catalogue before writing reusable cache. Every parsed locality must contain the completion metadata that BASIA publishes for a locality (`Razem wpisów` and `Indeksujący`); malformed localities or responses interrupted inside a locality fail before cache promotion.

Unlike bounded search, the BASIA catalogue does not expose a known page-level completion marker comparable to `Czas wyszukiwania`. The provider therefore does **not** claim that it can prove global completeness of an otherwise structurally valid snapshot that the upstream server itself might have ended cleanly after a prefix of the catalogue. The cache guarantee is intentionally limited to transport success plus structural validity of the returned snapshot.

A valid catalogue response is cached as:

```text
raw/basia/catalog_pl.html
raw/basia/catalog_pl.html.meta.json
```

Normal repeated discovery uses that cached response. `--refresh` or `listCatalogUnits(true)` bypasses it and fetches a new catalogue.

The catalogue does not use the bounded-search completion checkpoint because it is a single cached discovery snapshot rather than a person-record acquisition job.

## Raw values and provenance

Each bounded-search `ExternalIndexRecord` preserves provider values needed for audit and later import, including where available:

- raw record-type label and provider result token,
- locality/place and unit type,
- indexed person, parents, spouse and other named people,
- fuzzy-match similarity,
- indexer comment,
- archive/signature,
- indexer and provider-added date,
- stable numeric record id and permalink,
- canonical query configuration and deterministic fingerprint,
- retrieval timestamp, raw-response path and SHA-256,
- parser version and result index.

Provider-published descriptive text uses `ValueRepresentation::indexerRendering('basia', ...)`. It is preserved as the indexer/provider rendering and is **not** asserted to be the original document wording.

Each catalog unit similarly retains the catalogue URL, retrieval timestamp, raw-response path/hash, parser version and catalog index. `catalog_unit_key` is a deterministic BASIA-local discovery key derived from stable locality/unit identity fields; it is not a MyTree `SourceId` and does not establish cross-provider source identity.

## Scan links

A BASIA search result may contain a link to a scan hosted by another service. In this provider that URL is only a **source locator / acquisition lead** stored with the index record. BASIA support does not download scan assets and does not bypass the separate scan-provider boundary.

## Deliberate non-goals

This implementation does not provide:

- automated partitioning of the full BASIA corpus,
- bulk mirroring,
- scan downloading,
- direct creation of MyTree `Person` or `Source` records,
- web-application orchestration,
- conversion of BASIA civil-registry units into fake parishes,
- replacement/removal of the existing `AvailableParish` contract.

Those concerns belong to separate acquisition/discovery/orchestration layers or later compatibility decisions.
