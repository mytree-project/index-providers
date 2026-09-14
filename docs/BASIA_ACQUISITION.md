# BASIA bounded search

BASIA support in `mytree/index-providers` is intentionally a **bounded, low-rate search provider**, not a bulk crawler or mirror of the BASIA corpus.

## Provider contract

The provider key is `basia`. A search is built with the immutable `BasiaSearch` API and produces the existing `mytree.external-index-record.v1` contract.

Supported canonical record types are:

- `birth`,
- `marriage`,
- `death`,
- `banns`,
- `other`.

`banns` is distinct from `marriage`. `other` is used only when BASIA explicitly labels the record as its provider-side “other” category. If a future BASIA category is observed and is not mapped, the record type is emitted as an opaque provider-qualified value such as `provider:basia:<token>` rather than being silently coerced to `other`.

The BASIA form codes (`a`, `b`, `c`, `d`, `z`) remain private implementation details of the adapter. Callers use only canonical `RecordType` values.

## CLI

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

## PHP API

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

## HTTP boundary

`BasiaProvider` accepts a standard `Psr\Http\Client\ClientInterface` and optional PSR-17 request/stream factories. The provider submits the BASIA advanced search as `POST /` with `application/x-www-form-urlencoded` data.

A standalone composition with BASIA-safe defaults can be built as follows:

```php
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;
use MyTree\IndexProviders\Provider\BasiaProvider;

$http = DefaultHttpClientFactory::create(
    timeoutSeconds: BasiaProvider::DEFAULT_TIMEOUT_SECONDS,
    retryableMethods: ['GET', 'HEAD', 'POST'],
);
```

Retry is a transport concern. A structurally incomplete HTTP `200` response is **not** blindly retried by the generic retry layer.

## Completeness and broad-query limitation

BASIA searches can take a long time. A query that is too broad can return HTTP `200` with a truncated/unusable page. The parser therefore requires the provider’s search-complete marker (`Czas wyszukiwania`) before treating a response as complete.

The provider distinguishes:

- complete result page with records — success,
- complete result page with zero records — valid empty success,
- HTTP `200` without the completion marker — `BasiaIncompleteResponseException`,
- structurally malformed/unexpected result boxes — parsing failure.

Incomplete or malformed responses are not written as successful raw-cache entries and do not create a completion checkpoint. The caller should narrow the search (for example by adding a given name, year range, place, record type, or a stricter similarity value) and retry deliberately.

## Raw values and provenance

Each mapped `ExternalIndexRecord` preserves provider values needed for audit and later import, including where available:

- raw record-type label and provider code,
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

## Scan links

A BASIA result may contain a link to a scan hosted by another service. In this provider that URL is only a **source locator / acquisition lead** stored with the index record. BASIA support does not download scan assets and does not bypass the separate scan-provider boundary.

## Cache and resume

Only a response that passes completeness and structural parsing is saved as a reusable `raw/basia/query_<fingerprint>.html` response and marked complete in checkpoints. Re-running the same canonical search with the same output directory therefore skips completed work deterministically.

`--restart` ignores the existing BASIA cache/checkpoint and obtains a new response.

## Deliberate non-goals

This implementation does not provide:

- BASIA catalog/parish discovery,
- automated partitioning of the full BASIA corpus,
- bulk mirroring,
- scan downloading,
- direct creation of MyTree `Person` records,
- web-application orchestration.

Those concerns belong to separate acquisition/discovery/orchestration layers.
