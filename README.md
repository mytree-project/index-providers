# MyTree Index Providers

Samodzielny pakiet PHP do pobierania indeksów genealogicznych z:

- **Geneteka** — JSON z wewnętrznego endpointu `getAct.php`, stronicowanie,
- **Metryki-Wołyń** — HTML wyszukiwarki, pobieranie parafii rok po roku.

Pakiet nie zależy od Laravela. Providerzy przyjmują standardowy klient HTTP PSR-18, więc MyTree lub inna aplikacja może wstrzyknąć własną implementację bez adaptera do niestandardowego interfejsu MyTree. Standalone CLI używa domyślnej kompozycji opartej na Guzzle 7.

## Wymagania

- PHP 8.2+
- Composer 2
- zależności z `composer.json` (`guzzlehttp/guzzle` oraz kontrakty PSR-18/PSR-7/PSR-17)
- brak zależności od Laravel

Przed użyciem biblioteki lub CLI zainstaluj zależności:

```bash
composer install
```

## HTTP i integracja

Publiczną granicą klienta HTTP jest `Psr\Http\Client\ClientInterface`. Requesty i responses używają PSR-7, a provider może przyjąć również własny `Psr\Http\Message\RequestFactoryInterface`.

Domyślny klient standalone można utworzyć przez:

```php
use MyTree\IndexProviders\Http\DefaultHttpClientFactory;

$http = DefaultHttpClientFactory::create(
    timeoutSeconds: 60,
    maxAttempts: 3,
);
```

`NativeHttpClient` pozostaje tymczasowo jako deprecated compatibility shim, ale sam implementuje już PSR-18 i deleguje do domyślnego stosu Guzzle.

Aplikacja hostująca może zamiast tego wstrzyknąć dowolny kompatybilny klient PSR-18 oraz, opcjonalnie, własną fabrykę PSR-17:

```php
$provider = new GenetekaProvider(
    $myPsr18Client,
    $checkpointStore,
    $rawResponseStore,
    requestFactory: $myPsr17RequestFactory,
);
```

Domyślna kompozycja zachowuje dotychczasową politykę retry dla błędów transportowych, `429` i `5xx`, a redirecty dla `GET`/`HEAD` są obsługiwane jawnie. Retry domyślnie nie obejmuje `POST`; provider wykonujący bezpieczny read-only POST musi włączyć go świadomie. Rate limiting pozostaje osobną odpowiedzialnością providera.

Więcej: [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md).

## Szybki start

### Geneteka — Imbramowice, małopolskie

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

Typ wejściowy jest kanonicznym `RecordType` MyTree, niezależnym od oznaczeń konkretnego portalu:

- `birth` — urodzenia,
- `marriage` — śluby,
- `death` — zgony.

Jedna operacja Geneteki odpowiada jednemu stanowi formularza i jednemu typowi rekordu. Agregowanie kilku typów, dzielenie pracy na przedziały i planowanie kolejnych zapytań pozostaje odpowiedzialnością warstwy wyższej.

### Fluent API Geneteki

Provider nie przyjmuje rosnącej listy argumentów w `acquire(...)`. Każde zapytanie buduje się jako niemutowalną konfigurację:

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

Zweryfikowane pola formularza mają wygodne metody (`person`, `secondPerson`, `years`, `exact`, `excludeParents`). Dla pól specyficznych dla Geneteki, które nie mają jeszcze dedykowanej metody, można użyć kontrolowanego escape hatch:

```php
$query = $query->formParameter('nazwa_pola_geneteki', 'wartość');
```

Parametry transportowe `bdm`, `w`, `rid`, `length` i `start` są zastrzeżone i nie mogą zostać nadpisane przez `formParameter()`.

### Dostępność lat i identyfikatory per typ

Interfejs Geneteki publikuje dla wybranej parafii zakresy lat, które mogą być nieciągłe, a linki Urodzenia/Małżeństwa/Zgony mogą prowadzić do różnych wartości `rid`. Provider udostępnia te informacje osobno od samej akwizycji:

```php
$availability = $provider->discoverAvailability(
    region: '10pl',
    anchorType: RecordType::Birth,
    anchorParishId: '4257',
);
```

Każdy `GenetekaRecordAvailability` zawiera `recordType`, właściwy dla niego `providerParishId` oraz listę `YearRange[]`. Biblioteka nie wykorzystuje tych zakresów do automatycznego planowania pobierania — może to zrobić późniejszy Acquisition Manager / warstwa Laravelowa.

CLI diagnostyczne:

```bash
php bin/mytree-index geneteka --availability \
  --region=10pl --parish-id=4257 --type=birth --format=json
```

### Metryki-Wołyń — Szumsk

```bash
php bin/mytree-index wolyn \
  --parish=Szumsk \
  --from=1731 \
  --to=1943 \
  --output=var/szumsk
```

Metryki-Wołyń jest pobierany **rok po roku**. Jedna odpowiedź może zawierać sekcje:

- `birth`,
- `marriage`,
- `death`,
- `parish_census`.

## Odkrywanie dostępnych parafii

Pakiet udostępnia tryb discovery, który nie pobiera indeksów osób, tylko listę parafii dostępnych w danym portalu. Wynik ma wspólny kontrakt `mytree.available-parish.v1`, dzięki czemu może później zasilać selektor parafii w interfejsie MyTree/Laravel.

### Geneteka — jeden region

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp
```

Dla Geneteki `provider_parish_id` jest wartością `rid`, np.:

```text
REGION  ID    PARISH
------  ----  -----------
06mp    4812  Imbramowice
```

Lista jest pobierana z aktualnego formularza wyszukiwarki Geneteki. Jeżeli struktura formularza ulegnie zmianie, provider ma dodatkowy fallback wykorzystujący pole `parishes` odpowiedzi API `getAct.php`.

Możliwe jest również zebranie wszystkich regionów:

```bash
php bin/mytree-index geneteka --list-parishes --all-regions
```

To wykonuje wiele żądań (po jednym na region), dlatego nadal obowiązuje `--delay-ms`. Do zwykłego użycia lepiej preferować listę dla konkretnego regionu.

### Metryki-Wołyń

```bash
php bin/mytree-index wolyn --list-parishes
```

Provider korzysta ze strony **Zawartość** portalu, więc poza nazwą parafii zachowuje także deklarowane zakresy wpisów i pełnych indeksów dla:

- urodzeń,
- ślubów,
- zgonów,
- spisów parafian.

Przykładowy widok tabelaryczny:

```text
PARISH  BIRTHS     MARRIAGES  DEATHS     CENSUS
------  ---------  ---------  ---------  ------
Szumsk  1731-1926  1739-1943  1741-1939  1857
```

### Format wyniku discovery

Domyślnie wynik jest tabelą. Można uzyskać JSON lub JSONL:

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp --format=json
php bin/mytree-index wolyn --list-parishes --format=jsonl
```

Można też zapisać wynik do pliku:

```bash
php bin/mytree-index wolyn --list-parishes --format=json --save=parishes-wolyn.json
```

Przykładowy rekord:

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

Dla Metryki-Wołyń `provider_parish_id` jest `null`, ponieważ wyszukiwarka identyfikuje parafię tekstową nazwą. Zakresy dostępności znajdują się w `metadata.wpisy` i `metadata.indeksy`.

Discovery używa własnego cache surowych stron. Domyślnie jest to `var/discovery-cache`; można wskazać inne miejsce przez `--output=DIR`. Aby odświeżyć listę z portalu, użyj:

```bash
--refresh
```

## Rate limiting

Domyślnie program czeka co najmniej 2000 ms między kolejnymi żądaniami sieciowymi:

```bash
--delay-ms=2000
```

Nie zaleca się zmniejszania tego opóźnienia. Narzędzie jest przeznaczone do kontrolowanego, osobistego pozyskiwania danych. Przed większym pobieraniem należy upewnić się, że sposób użycia jest zgodny z zasadami/regulaminem danego serwisu.

## Wznawianie

Program zapisuje checkpoint po każdej kompletnej jednostce pracy:

- Geneteka — po stronie wyników,
- Metryki-Wołyń — po roku.

Ponowne uruchomienie tego samego polecenia z tym samym `--output` wznowi pracę.

Dla Geneteki cache i checkpointy są rozdzielane według deterministycznego fingerprintu całej konfiguracji zapytania (region, `rid`, typ rekordu, parametry formularza i rozmiar strony). Dzięki temu dwa różne filtry nie mogą przypadkowo współdzielić wyniku cache.

Surowe odpowiedzi są zapisywane w `raw/`. Jeżeli odpowiedź została pobrana, ale proces przerwał się przed checkpointem, przy wznowieniu program użyje lokalnego cache zamiast ponownie pytać serwis.

`records.jsonl` deduplikuje rekordy po `provider_record_id`, dzięki czemu przerwanie w środku jednostki nie powinno tworzyć duplikatów po wznowieniu.

### Pełne pobranie od nowa

```bash
... --restart
```

`--restart`:

- usuwa `records.jsonl`,
- usuwa checkpointy,
- ignoruje istniejący cache w czasie pobierania,
- nadpisuje odpowiadające pliki raw nową odpowiedzią.

## Struktura wyjścia

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

Dla Wołynia:

```text
raw/wolyn-metryki/
├── szumsk_1731.html
├── szumsk_1731.html.meta.json
└── ...
```

## Format `ExternalIndexRecord`

Każda linia JSONL ma stabilny kontrakt:

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

Najważniejsza zasada: `fields` ułatwia dalszą pracę, ale `raw` zachowuje oryginalne wartości indeksu. Narzędzie nie próbuje rozstrzygać niepewności typu `20?`, wariantów nazwiska ani semantyki tekstu w uwagach.

## Provenance

Każdy rekord zawiera m.in.:

- URL żądania,
- czas pobrania,
- ścieżkę do surowej odpowiedzi,
- SHA-256 surowej odpowiedzi,
- parametr parafii/regionu,
- indeks wiersza/strony lub roku.

Dzięki temu późniejszy importer MyTree może utworzyć `Source`, `SourceLocator`, `Mention` i `Claim` bez utraty informacji o pochodzeniu.

## Testy

Testy są napisane w PHPUnit. Najpierw zainstaluj zależności:

```bash
composer install
```

Następnie uruchom cały zestaw:

```bash
composer test
```

Bezpośrednie uruchomienie PHPUnit:

```bash
vendor/bin/phpunit
```

Normalny zestaw testów jest offline i nie powinien zależeć od dostępności serwisów zewnętrznych.

## Integracja z Laravel/MyTree

Zobacz [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md).
