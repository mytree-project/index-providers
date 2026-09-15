# Index-provider discovery

## Purpose

Discovery describes provider-published availability that can later be used to configure an acquisition. It does not download genealogical person records, does not create `ExternalIndexRecord`, and does not establish MyTree historical `Source` identity.

The package currently exposes two distinct discovery contracts because a parish-only abstraction cannot faithfully represent every provider.

## Legacy parish discovery

Geneteka and Metryki-Wołyń retain the compatibility model:

```text
AvailableParish
provider
providerParishId?
name
regionCode?
regionName?
metadata
```

Serialized output remains `mytree.available-parish.v1`.

### Geneteka

Primary strategy:

```text
index.php?op=gt&lang=pol&w=<region>&rid=A
    ↓
<select name="rid">
    ↓
<option value="<rid>">provider parish name</option>
```

A fallback attempts to use the `parishes` member returned by `getAct.php`.

Full discovery across all regions first reads `<select name="w">` and then discovers parishes independently for each region.

CLI:

```bash
php bin/mytree-index geneteka --list-parishes --region=06mp
php bin/mytree-index geneteka --list-parishes --all-regions
```

### Metryki-Wołyń

Parish discovery uses the provider's `Zawartość` page. The parser reads the table beginning with `Parafia / Parish`; subsequent `Wpisy` and `Indeksy` rows are attached to the preceding parish. Denomination/group headings without an availability row are not emitted as parishes.

CLI:

```bash
php bin/mytree-index wolyn --list-parishes
```

The exact Polish labels above are provider/source wording and are intentionally retained where needed for parsing and raw metadata.

## Generalized catalog discovery

BASIA implements the optional:

```text
IndexCatalogDiscoveryInterface
    → list<IndexCatalogUnit>
```

Serialized output uses `mytree.index-catalog-unit.v1`.

`IndexCatalogUnit` keeps locality/place separate from the indexed records-holding unit:

```text
locality
    ↓
indexed unit
    ↓
availability by record type
```

This allows one locality to contain, for example:

```text
Catholic parish
Protestant parish
Registry Office / USC
provider-declared other unit
```

without representing civil-registry data as a parish.

Known BASIA unit mappings are:

```text
Parafia katolicka       → unit_kind=parish, denomination=roman_catholic
Parafia ewangelicka     → unit_kind=parish, denomination=evangelical
Urząd Stanu Cywilnego   → unit_kind=civil_registry
provider-declared other → unit_kind=other
```

An unmapped future provider unit uses `provider:basia:<token>` instead of being silently mapped to `other`. A provider item without a unit label is preserved as `provider:basia:unlabeled`.

Availability is represented per record type using `YearRange[]`, so discontinuous coverage remains explicit.

CLI:

```bash
php bin/mytree-index basia --list-catalog
php bin/mytree-index basia --list-catalog --format=json
```

PHP:

```php
$units = $basia->listCatalogUnits();
$units = $basia->listCatalogUnits(refresh: true);
```

BASIA intentionally does not expose a fake `listParishes()` projection for catalog units.

## Cache and refresh

Raw discovery pages are retained through `RawResponseStore`. `--refresh` bypasses reusable discovery cache.

BASIA catalog responses are parsed before cache promotion. Every parsed locality must contain the expected structural completion metadata (`Razem wpisów` and `Indeksujący`). The provider does not claim a stronger global-completeness guarantee because BASIA publishes no known page-level completion marker equivalent to bounded search's `Czas wyszukiwania`.

## Output formats

Both discovery modes support:

```text
table
json
jsonl
```

The two contracts remain intentionally separate. Any future migration or deprecation of `AvailableParish` requires a separate compatibility decision.
