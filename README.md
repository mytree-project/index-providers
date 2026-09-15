# MyTree Index Providers

Samodzielny pakiet PHP do pobierania indeksów genealogicznych z:

- **Geneteka** — JSON z wewnętrznego endpointu `getAct.php`, stronicowanie,
- **Metryki-Wołyń** — HTML wyszukiwarki, pobieranie parafii rok po roku,
- **BASIA** — ograniczone (bounded), niskoczęstotliwościowe wyszukiwanie przez publiczny formularz rozszerzony oraz discovery katalogu zindeksowanych jednostek.

Pakiet nie zależy od Laravela. Providerzy przyjmują standardowy klient HTTP PSR-18, więc MyTree lub inna aplikacja może wstrzyknąć własną implementację bez adaptera do niestandardowego interfejsu MyTree. Standalone CLI używa domyślnej kompozycji opartej na Guzzle 7.

## Wymagania

- PHP 8.2+
- Composer 2
- rozszerzenie PHP DOM (`ext-dom`)
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

Domyślna kompozycja zachowuje dotychczasową politykę retry dla błędów transportowych, `429` i `5xx`, a redirecty dla `GET`/`HEAD` są obsługiwane jawnie. Retry domyślnie nie obejmuje `POST`; provider wykonujący bezpieczny read-only POST musi włączyć go świadomie. CLI BASIA robi to jawnie, ponieważ wyszukiwanie BASIA jest odczytowym `POST` z odtwarzalnym body.

Rate limiting pozostaje osobną odpowiedzialnością providera. Dla BASIA domyślny interwał CLI wynosi 5000 ms, a timeout 200 s; Geneteka i Metryki-Wołyń zachowują dotychczasowe domyślne 2000 ms i 60 s.

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

### BASIA — bounded search

BASIA nie jest obsługiwana jako crawler całej bazy. Provider wykonuje jedno świadomie ograniczone wyszukiwanie odpowiadające stanowi publicznego formularza rozszerzonego:

```bash
php bin/mytree-index basia \
  --surname=Kowalski \
  --name=Jan \
  --from=1880 \
  --to=1900 \
  --type=birth \
  --output=var/basia-kowalski
```

Wymagany jest co najmniej jeden z filtrów `--surname`, `--name` lub `--place`. Obsługiwane kanoniczne typy to:

- `birth`,
- `marriage`,
- `death`,
- `banns`,
- `other`.

`banns` jest osobnym typem, a `other` oznacza wyłącznie kategorię „inne” deklarowaną przez providera. Nieznana przyszła kategoria BASIA jest zachowywana jako `provider:basia:<token>` zamiast automatycznie stawać się `other`.

Dodatkowe filtry CLI obejmują `--place`, `--distance-km`, `--similarity`, `--sex`, `--relation` i `--unit-type`. Kody formularza BASIA nie są częścią publicznego API — caller używa wyłącznie kanonicznych wartości.

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

BASIA bywa wolna dla szerokich zapytań. HTTP `200` nie jest automatycznie uznawane za sukces: parser wymaga markera ukończonego wyszukiwania. Ucięta odpowiedź nie trafia do cache jako poprawna i nie tworzy checkpointu; należy wtedy zawęzić zapytanie i wykonać je ponownie. Pusty, ale kompletny wynik jest prawidłowym sukcesem.

Link do skanu zwrócony przez BASIA jest zachowywany jako locator / lead do dalszej akwizycji. Ten provider nie pobiera skanów.

Szczegóły: [docs/BASIA_ACQUISITION.md](docs/BASIA_ACQUISITION.md).

### BASIA — katalog zindeksowanych jednostek

Katalog BASIA (`content-all.php?lang=pl`) opisuje nie tylko parafie. Jedna miejscowość może mieć równolegle parafię katolicką, ewangelicką, urząd stanu cywilnego oraz inne jednostki. Dlatego ten tryb używa nowego addytywnego kontraktu `IndexCatalogUnit` zamiast `AvailableParish`.

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

`BasiaProvider` implementuje opcjonalny `IndexCatalogDiscoveryInterface`. Każda jednostka używa schematu `mytree.index-catalog-unit.v1` i rozdziela `locality` od records-holding unit. Początkowe mapowania to:

```text
Parafia katolicka       -> parish / roman_catholic
Parafia ewangelicka     -> parish / evangelical
Urząd Stanu Cywilnego   -> civil_registry
provider-declared other -> other
```

Nieznany przyszły typ jednostki pozostaje `provider:basia:<token>`, a wpis bez etykiety jednostki jest zachowany jako `provider:basia:unlabeled`. Nie powstają fikcyjne parafie.

Dostępność typu aktu przechowuje listę `YearRange`, więc zakresy nieciągłe pozostają nieciągłe. Katalog zachowuje także surowe etykiety, powiat, lokalny total wpisów, indeksujących oraz provenance surowej odpowiedzi.

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

## Discovery providerów

Pakiet ma dwa jawnie rozdzielone kontrakty discovery.

### Legacy parish discovery

Geneteka i Metryki-Wołyń zachowują `AvailableParish` / `mytree.available-parish.v1` oraz `--list-parishes`. Nie zmieniamy ich zwrotnego typu tylko dlatego, że istnieje bardziej ogólny katalog.

#### Geneteka — jeden region

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

#### Metryki-Wołyń

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

### Generalized catalog discovery

Nowy opcjonalny kontrakt `IndexCatalogDiscoveryInterface` zwraca `IndexCatalogUnit[]`. W P1 implementuje go BASIA. `AvailableParish` nie jest usuwany ani zmieniany.

Przykładowy wynik JSON BASIA:

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

### Format wyniku discovery

Domyślnie wynik jest tabelą. Można uzyskać JSON lub JSONL:

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp --format=json
php bin/mytree-index wolyn --list-parishes --format=jsonl
php bin/mytree-index basia --list-catalog --format=json
```

Można też zapisać wynik do pliku:

```bash
php bin/mytree-index wolyn --list-parishes --format=json --save=parishes-wolyn.json
php bin/mytree-index basia --list-catalog --format=json --save=basia-catalog.json
```

Przykładowy rekord legacy parish discovery:

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

Discovery używa własnego cache surowych stron. Domyślnie jest to `var/discovery-cache`; można wskazać inne miejsce przez `--output=DIR`. Aby odświeżyć dane z portalu, użyj:

```bash
--refresh
```

BASIA cache'uje kompletnie sparsowany katalog jako `raw/basia/catalog_pl.html`. Odpowiedź niekompletna lub strukturalnie błędna nie jest promowana do poprawnego cache.

## Rate limiting

Domyślnie Geneteka i Metryki-Wołyń czekają co najmniej 2000 ms między kolejnymi żądaniami sieciowymi. BASIA ma bardziej konserwatywny domyślny interwał 5000 ms:

```bash
--delay-ms=5000
```

Nie zaleca się zmniejszania tych opóźnień. Narzędzie jest przeznaczone do kontrolowanego, osobistego pozyskiwania danych. Przed większym pobieraniem należy upewnić się, że sposób użycia jest zgodny z zasadami/regulaminem danego serwisu.

## Wznawianie

Program zapisuje checkpoint po każdej kompletnej jednostce pracy:

- Geneteka — po stronie wyników,
- Metryki-Wołyń — po roku,
- BASIA bounded search — po kompletnym zapytaniu.

Ponowne uruchomienie tego samego polecenia z tym samym `--output` wznowi pracę.

Dla Geneteki cache i checkpointy są rozdzielane według deterministycznego fingerprintu całej konfiguracji zapytania (region, `rid`, typ rekordu, parametry formularza i rozmiar strony). BASIA bounded search używa analogicznego fingerprintu kanonicznej konfiguracji wyszukiwania; provider zapisuje cache dopiero po potwierdzeniu kompletności odpowiedzi.

BASIA catalog discovery jest pojedynczym snapshotem discovery: korzysta z raw cache i `--refresh`, ale nie tworzy checkpointu akwizycji rekordów osób.

Surowe odpowiedzi są zapisywane w `raw/`. Jeżeli odpowiedź została pobrana, ale proces przerwał się przed checkpointem, przy wznowieniu program użyje lokalnego cache zamiast ponownie pytać serwis, o ile odpowiedź przeszła walidację kompletności.

`records.jsonl` deduplikuje rekordy po `provider_record_id`, dzięki czemu przerwanie w środku jednostki nie powinno tworzyć duplikatów po wznowieniu.

### Pełne pobranie od nowa

```bash
... --restart
```

`--restart` dla akwizycji rekordów:

- usuwa `records.jsonl`,
- usuwa checkpointy,
- ignoruje istniejący cache w czasie pobierania,
- nadpisuje odpowiadające pliki raw nową odpowiedzią.

Dla discovery preferowanym jawnym mechanizmem odświeżenia jest `--refresh`.

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

Dla BASIA:

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

The initial terminal adapter uses ANSI + `stty` and therefore targets Unix-like interactive terminals. See [docs/RESULT_VIEWER.md](docs/RESULT_VIEWER.md) for validation rules, details-view behavior, search semantics and current limitations.

## Format `ExternalIndexRecord`

Każda linia JSONL akwizycji rekordów ma stabilny kontrakt:

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

Najważniejsza zasada: `fields` ułatwia dalszą pracę, ale `raw` zachowuje wartości indeksu. Narzędzie nie próbuje rozstrzygać niepewności typu `20?`, wariantów nazwiska ani semantyki tekstu w uwagach. W BASIA wartości opisowe są dodatkowo oznaczane jako `indexer_rendering`, aby nie sugerować, że są literalnym brzmieniem dokumentu źródłowego.

## Provenance

Każdy rekord zawiera m.in.:

- URL żądania lub stabilny permalink rekordu,
- czas pobrania,
- ścieżkę do surowej odpowiedzi,
- SHA-256 surowej odpowiedzi,
- konfigurację/fingerprint zapytania właściwe dla providera,
- indeks wiersza/strony/roku lub wyniku,
- wersję parsera tam, gdzie ma to znaczenie dla odtwarzalności.

`IndexCatalogUnit` również zachowuje URL katalogu, czas pobrania, ścieżkę/hash raw response, wersję parsera i indeks pozycji katalogowej. `catalog_unit_key` jest lokalnym, deterministycznym kluczem discovery, nie `SourceId`.

Dzięki temu późniejszy importer MyTree może zachować pochodzenie danych bez utraty granicy odpowiedzialności pomiędzy discovery, akwizycją i identyfikacją źródła.

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

Normalny zestaw testów jest offline i nie powinien zależeć od dostępności serwisów zewnętrznych. BASIA ma statyczne fixtures dla bounded search oraz osobne fixtures katalogu obejmujące wiele jednostek jednej miejscowości, nieciągłe zakresy, typy jawne/nieznane i odpowiedź niekompletną.

## Integracja z Laravel/MyTree

Zobacz [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md) oraz [docs/BASIA_ACQUISITION.md](docs/BASIA_ACQUISITION.md).
