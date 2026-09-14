# Integracja z Laravel / MyTree

Pakiet rozdziela odpowiedzialności providerów od infrastruktury aplikacji-hostującej:

```text
Provider
  ├── Psr\Http\Client\ClientInterface (PSR-18)
  ├── Psr\Http\Message\RequestFactoryInterface (PSR-17)
  ├── CheckpointStoreInterface
  ├── RawResponseStore
  └── RecordWriterInterface
```

Requesty i responses używają kontraktów PSR-7. Providerzy pozostają framework-independent i nie zależą od Eloquent ani Laravel HTTP Client.

## Domyślna implementacja HTTP

Standalone `mytree/index-providers` instaluje Guzzle 7 i używa go jako domyślnej implementacji PSR-18. `DefaultHttpClientFactory` składa transport z:

- Guzzle jako klienta sieciowego,
- jawnej obsługi redirectów dla `GET`/`HEAD`,
- ograniczonego retry dla błędów transportowych, `429` i `5xx`,
- konfigurowalnego timeoutu i User-Agent.

`NativeHttpClient` pozostaje tymczasowo jako deprecated compatibility shim, ale sam implementuje już PSR-18 i nie jest publiczną granicą integracyjną.

## Wstrzyknięcie klienta przez MyTree

MyTree nie musi używać domyślnego klienta. Provider przyjmuje dowolny `Psr\Http\Client\ClientInterface`. Można również podać własny `RequestFactoryInterface` jako ostatni argument konstruktora providera.

Przykład z Guzzle:

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

Ta sama zasada działa z innym klientem zgodnym z PSR-18. Provider nie powinien znać sposobu konfiguracji kontenera ani konkretnej biblioteki HTTP hosta.

### Laravel service container

W aplikacji MyTree naturalną granicą jest composition root / service provider:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

$this->app->singleton(ClientInterface::class, function () {
    return $this->configuredPsr18Client();
});

$this->app->singleton(RequestFactoryInterface::class, function () {
    return $this->configuredPsr17Factory();
});
```

Nie jest wymagany adapter `LaravelHttpClient implements MyTreeHttpClientInterface`, ponieważ pakiet nie definiuje już własnego interfejsu klienta HTTP.

## Retry, timeout i rate limiting

PSR-18 celowo nie definiuje timeoutów ani retry. Są one częścią composition/infrastructure:

- timeout jest konfiguracją konkretnego klienta,
- retry może być realizowane przez `RetryingHttpClient`, który również implementuje PSR-18,
- domyślne retry obejmuje tylko `GET` i `HEAD`,
- provider wymagający bezpiecznego read-only `POST` musi jawnie włączyć `POST` do polityki retry,
- rate limiting pozostaje osobnym mechanizmem providera i nie jest częścią PSR-18.

Redirecty są obsługiwane jawnie dla `GET`/`HEAD`. Nie zakładamy, że `sendRequest()` konkretnej implementacji automatycznie podąża za redirectami.

## Pozostałe adaptery MyTree

### `LaravelCheckpointStore`

Implementuje `CheckpointStoreInterface`. Możliwe backendy:

- tabela PostgreSQL — najlepsza do audytu,
- Cache/Redis — szybsze, ale mniej trwałe,
- Laravel Storage — najbliższe aktualnej wersji CLI.

### `MyTreeExternalIndexWriter`

Implementuje `RecordWriterInterface` i zapisuje `ExternalIndexRecord` do warstwy stagingowej MyTree, **nie bezpośrednio do `Person`**.

Proponowany przepływ:

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

Warstwa stagingowa powinna zachować `provider_record_id`, `raw`, `fields` i pełne `provenance`.

## Kolejki

Dla większych importów naturalnym krokiem będzie rozbijanie pracy na małe joby:

- Geneteka: `(region, rid, type, page)`,
- Metryki-Wołyń: `(parish, year)`.

To odpowiada jednostkom checkpointów używanym już przez narzędzie CLI.

## Idempotencja

`provider_record_id` powinien mieć unikalny indeks w tabeli stagingowej. Dzięki temu retry joba nie utworzy duplikatu.

## Granica odpowiedzialności

Provider ma pozyskiwać i wiernie reprezentować indeks. Nie powinien:

- scalać osób,
- tworzyć hipotez tożsamości,
- normalizować wariantów nazw jako „prawdę”,
- traktować miejsca zdarzenia jako miejsca zamieszkania,
- interpretować tekstu uwag jako pewnych relacji bez osobnej warstwy ekstrakcji.

## Discovery parafii

Providerzy udostępniają również warstwę discovery niezależną od pobierania rekordów:

```php
$parishes = $geneteka->listParishes('06mp');
$parishes = $wolyn->listParishes();
```

Każdy element jest `AvailableParish` i ma wspólny kontrakt:

```text
provider
providerParishId
name
regionCode
regionName
metadata
```

W przyszłej wtyczce Laravel naturalne zastosowanie to:

```text
Provider selection
    ↓
region (jeśli wymagany)
    ↓
AvailableParish[]
    ↓
Filament Select / searchable relation-like picker
    ↓
konfiguracja zadania acquisition
```

Lista discovery nie powinna być wiązana z modelem `Person`. Może być cachowana osobno (np. tabela `external_provider_parishes` lub Laravel Cache), wraz z `retrieved_at` i surowym payloadem dla audytu.

Dla Geneteki warto przechowywać `region_code + provider_parish_id` jako klucz zewnętrzny. Dla Metryki-Wołyń obecnie kluczem wejściowym jest nazwa parafii, więc lokalny rekord discovery powinien zachować również dokładną pisownię zwróconą przez portal.
