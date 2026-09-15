# Laravel / MyTree integration

The package separates provider responsibilities from host-application infrastructure:

```text
Provider
  ├── Psr\Http\Client\ClientInterface (PSR-18)
  ├── Psr\Http\Message\RequestFactoryInterface (PSR-17)
  ├── CheckpointStoreInterface
  ├── RawResponseStore
  ├── RecordWriterInterface
  └── optional IndexCatalogDiscoveryInterface
```

Requests and responses use PSR-7 contracts. Providers remain framework-independent and do not depend on Eloquent or Laravel HTTP Client.

This document describes integration boundaries. Actual provider registration, UI configuration, persistence adapters, and acquisition orchestration belong to the MyTree web application integration milestone rather than to this standalone package.

## Default HTTP composition

Standalone `mytree/index-providers` installs Guzzle 7 and uses it as the default PSR-18 implementation. `DefaultHttpClientFactory` composes:

- Guzzle as the network client,
- explicit redirect handling for `GET`/`HEAD`,
- bounded retry for transport failures, HTTP `429`, and `5xx`,
- configurable timeout and User-Agent.

`NativeHttpClient` remains temporarily as a deprecated compatibility shim. It implements PSR-18 and is not the public integration boundary.

## Injecting HTTP from MyTree

MyTree does not need to use the default client. Every provider accepts a `Psr\Http\Client\ClientInterface`. A host may also inject its own `RequestFactoryInterface` and, when request bodies are required, `StreamFactoryInterface`.

Example with Guzzle:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use MyTree\IndexProviders\Provider\GenetekaProvider;

$http = new Client([
    'timeout' => 60,
    'http_errors' => false,
    'headers' => [
        'User-Agent' => 'MyTree/1.0',
    ],
]);

$messages = new HttpFactory();

$provider = new GenetekaProvider(
    $http,
    $checkpointStore,
    $rawResponseStore,
    requestFactory: $messages,
);
```

The same principle applies to any compatible PSR-18 client. A provider should not know how the host service container or HTTP library is configured.

### Laravel service container

A natural MyTree composition boundary is a service provider/composition root:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

$this->app->singleton(ClientInterface::class, function () {
    return $this->configuredPsr18Client();
});

$this->app->singleton(RequestFactoryInterface::class, function () {
    return $this->configuredPsr17Factory();
});

$this->app->singleton(StreamFactoryInterface::class, function () {
    return $this->configuredPsr17Factory();
});
```

An adapter such as `LaravelHttpClient implements MyTreeHttpClientInterface` is not required because the package no longer defines a custom HTTP-client contract.

## Retry, timeout, redirects, and rate limiting

PSR-18 deliberately does not define timeout or retry semantics. They belong to composition/infrastructure:

- timeout is configuration of the concrete client,
- `RetryingHttpClient` provides bounded retry while remaining PSR-18 compatible,
- retry defaults to `GET` and `HEAD`,
- a provider that uses a replayable read-only `POST` must explicitly include `POST` in its retry policy,
- provider rate limiting remains separate from PSR-18.

Redirects are handled explicitly for `GET`/`HEAD`; callers should not assume that an arbitrary PSR-18 implementation automatically follows redirects.

### BASIA HTTP composition

BASIA bounded search submits the public advanced form as read-only `POST application/x-www-form-urlencoded`. A conservative MyTree composition should match the standalone defaults:

```php
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;
use MyTree\IndexProviders\Provider\BasiaProvider;

$http = DefaultHttpClientFactory::create(
    timeoutSeconds: BasiaProvider::DEFAULT_TIMEOUT_SECONDS, // 200 s
    retryableMethods: ['GET', 'HEAD', 'POST'],
);

$basia = new BasiaProvider(
    $http,
    $checkpointStore,
    $rawResponseStore,
    $rateLimiter, // minimum 5000 ms by default
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
);
```

BASIA search is a **bounded-search capability**. The Laravel/Acquisition Manager layer should submit deliberately constrained queries rather than iterate or mirror the full portal. `BasiaIncompleteResponseException` means the HTTP response may have been `200` but the page did not contain the upstream search-complete marker. Such a response is not a successful cache/checkpoint result; the orchestration layer should narrow the query or surface the problem to the user instead of entering an unconditional retry loop.

The same `BasiaProvider` also implements the independent optional `IndexCatalogDiscoveryInterface`. Catalog discovery performs `GET content-all.php?lang=pl`, parses the snapshot before reusable-cache promotion, and returns `IndexCatalogUnit[]`. This describes provider-published index availability, not person records and not MyTree `Source` entities.

## Host adapters

### `LaravelCheckpointStore`

A future MyTree adapter can implement `CheckpointStoreInterface`. Possible backends include:

- PostgreSQL table — strongest auditability,
- Cache/Redis — fast but less durable,
- Laravel Storage — closest to the current standalone CLI implementation.

### `MyTreeExternalIndexWriter`

A MyTree adapter can implement `RecordWriterInterface` and write `ExternalIndexRecord` to a staging/acquisition boundary, **not directly to `Person`**.

Recommended flow:

```text
ExternalIndexRecord
    ↓
Acquisition/Staging record
    ↓
Source importer
    ↓
Source
Mention
Claim
SourceLocator
```

The staging layer should retain `provider_record_id`, `raw`, `fields`, `representation`, and full `provenance`.

For BASIA, `parish` may be `null` even when the result carries locality/unit information. BASIA also indexes non-parish units such as civil registry offices, so an importer must not force provider locality/unit data into parish semantics.

A scan URL in a BASIA record is a locator/acquisition lead. It must not be treated automatically as a downloaded `SourceAsset`; downloading belongs to the separate scan-provider boundary.

## Queue/job boundaries

For larger acquisitions, useful work units are naturally provider-specific:

- Geneteka: `(region, rid, type, page)`,
- Metryki-Wołyń: `(parish, year)`,
- BASIA bounded search: one explicitly constrained query identified by the fingerprint of its canonical configuration.

Do not automatically generate a broad BASIA query grid to mirror the portal. Any future planning/partitioning strategy requires a separate architecture decision above the provider.

Catalog discovery is a separate provider snapshot. It must not be turned into a queue of person records or treated as proof that an arbitrary broad BASIA search query would be complete.

## Idempotency and identities

`provider_record_id` should normally have a unique constraint in the staging table so retrying a job does not create duplicates.

BASIA search prefers the provider's stable numeric record identifier. If it is missing, the adapter creates a deterministic fallback from the available permalink and mapped record data.

`IndexCatalogUnit::catalogUnitKey` has a different role. It is a deterministic provider-local discovery key. It is not a `SourceId`, not a `provider_record_id`, and must not be used for cross-provider source reconciliation.

Metryki-Wołyń also preserves unknown future section types as provider-qualified opaque `record_type` values. For such a section the provider retains the raw table without guessing column semantics; a host importer must treat the corresponding `fields.provider_table` projection as opaque provider data unless a later mapping is explicitly accepted.

## Responsibility boundary

An Index Provider acquires and faithfully represents external index/catalog data. It must not:

- merge historical people,
- create identity hypotheses,
- normalize a name variant into authoritative truth,
- treat event place as residence without supporting evidence,
- interpret free-text notes as certain relations without a separate extraction layer,
- treat a BASIA indexer rendering as literal archival-document wording,
- treat an `IndexCatalogUnit` as a historical `Source`,
- convert a civil registry office into a parish for UI convenience.

BASIA search uses `ValueRepresentation::indexerRendering(...)`, preserving that indexed descriptive values may include transcription, transliteration, translation, or normalization performed by an indexer.

Name processing remains downstream of this package. Provider values stay source/provider faithful; normalization/transliteration candidates belong to the separate name-processing boundary.

## Legacy parish discovery

Geneteka and Metryki-Wołyń retain the existing parish-oriented discovery APIs:

```php
$parishes = $geneteka->listParishes('06mp');
$parishes = $wolyn->listParishes();
```

Each result is an `AvailableParish` with:

```text
provider
providerParishId
name
regionCode
regionName
metadata
```

A future Laravel flow may use this data as:

```text
Provider selection
    ↓
region (when required)
    ↓
AvailableParish[]
    ↓
Filament Select / searchable picker
    ↓
acquisition-job configuration
```

Parish discovery data should not be attached to `Person`. It may be cached separately, for example in an `external_provider_parishes` table or Laravel Cache, together with retrieval metadata and raw provenance.

For Geneteka, `region_code + provider_parish_id` is a useful provider-local key. For Metryki-Wołyń the current search input identifies a parish by name, so local discovery storage should retain the exact provider spelling.

`AvailableParish` / `mytree.available-parish.v1` is not automatically replaced by the generalized contract. Deprecating or migrating this API requires a separate compatibility decision.

## Generalized index catalog discovery

A provider that can describe a broader indexed-content catalog may implement:

```text
IndexCatalogDiscoveryInterface
    → IndexCatalogUnit[]
```

BASIA implements this contract without adding a fake `listParishes()` projection.

`IndexCatalogUnit` separates:

```text
locality/place
    ↓
indexed / records-holding unit
    ↓
availability by record type
```

One locality may therefore expose, for example:

```text
Catholic parish
Protestant parish
Registry Office / USC
provider-defined other unit
```

without semantic coercion. Serialized output uses `mytree.index-catalog-unit.v1`.

Example:

```php
use MyTree\IndexProviders\Contracts\IndexCatalogDiscoveryInterface;

if ($basia instanceof IndexCatalogDiscoveryInterface) {
    $units = $basia->listCatalogUnits();
}
```

A Laravel integration may cache catalog snapshots in a dedicated table such as `external_provider_catalog_units` or another explicit cache. Useful retained data includes:

```text
provider
catalog_unit_key
serialized mytree.index-catalog-unit.v1 payload
retrieved_at
raw-response provenance / hash
```

Do not map `IndexCatalogUnit` directly to `Source`, `Person`, or a parish relationship. It is discovery/planning data that can feed selectors and Acquisition Manager configuration.

A possible future UI flow is:

```text
Provider = BASIA
    ↓
IndexCatalogUnit discovery/cache
    ↓
locality
    ↓
unit (parish / civil registry / other)
    ↓
record type + advertised YearRange[]
    ↓
bounded acquisition-job configuration
```

Discontinuous `YearRange[]` should remain discontinuous in the UI rather than being widened to a single minimum/maximum range. Likewise, locality-wide/provider-wide totals must not be shown as per-record-type counts unless the upstream service publishes that distinction.

See [BASIA_ACQUISITION.md](BASIA_ACQUISITION.md) for BASIA-specific behavior.
