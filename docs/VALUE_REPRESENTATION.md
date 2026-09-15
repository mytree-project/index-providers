# Value representation in external indexes

`ExternalIndexRecord` preserves values published by an index provider, but those values must not automatically be treated as a verbatim transcription of the historical document.

The currently supported providers publish human-created index data. Indexers may transcribe, transliterate, translate, abbreviate, or normalize names, surnames, place names, comments, and other descriptive values before they appear in the public index. A value such as `Józef`, `Krzemieniec`, or a surname rendered in Polish spelling can therefore be faithful to the **provider index** without being the literal wording or script of the archival source.

## `ValueRepresentation`

Records created by Geneteka, Metryki-Wołyń, and BASIA use the top-level `representation` metadata to make this distinction explicit. A representative value is:

```json
{
  "representation": {
    "kind": "indexer_rendering",
    "scope": "indexed_descriptive_values",
    "verbatim_from_provider": true,
    "original_document_wording_asserted": false,
    "language_hint": "pl",
    "script_hint": "Latn",
    "producer": {
      "type": "human_indexer",
      "provider": "geneteka",
      "indexer_id": "example.indexer"
    },
    "metadata": {
      "may_include": [
        "transcription",
        "transliteration",
        "translation",
        "normalization"
      ]
    }
  }
}
```

`indexer_id` is included when the provider exposes it and the parser can identify it reliably. It may be `null` when the provider does not publish a usable indexer identifier for the record.

## Semantics

`verbatim_from_provider=true` means the acquisition layer retains the provider's descriptive value without performing an additional semantic translation or transliteration. It does **not** mean byte-for-byte preservation of portal HTML: markup and whitespace may already have been structurally parsed into `fields` and `raw`.

`original_document_wording_asserted=false` is the important distinction. An index record does not assert that the archival document contains exactly the same spelling, language, or script.

`language_hint=pl` and `script_hint=Latn` describe the usual rendering of descriptive values in the currently supported Polish-facing index portals. They are hints, not a claim that every free-text fragment is Polish.

## Provider behavior

### Geneteka

Geneteka records use `ValueRepresentation::indexerRendering('geneteka', ...)`. Raw and structured values remain independently traceable to the provider response.

### Metryki-Wołyń

Metryki-Wołyń records use `ValueRepresentation::indexerRendering('wolyn-metryki', ...)`. Known table layouts expose the indexer where available. Future/unrecognized provider sections are still preserved as opaque provider-qualified records; the package retains their raw table data without guessing an indexer column or other semantic fields.

### BASIA

BASIA records use `ValueRepresentation::indexerRendering('basia', $indexer)`. This is especially important because BASIA exposes indexer names and descriptive comments that are useful provenance but are still provider/indexer renderings, not guaranteed historical-document wording.

The representation metadata applies to BASIA **search records** (`ExternalIndexRecord`). BASIA catalog discovery returns `IndexCatalogUnit`, which describes provider-published availability rather than a person/index record and therefore uses its own raw/provenance fields rather than `ValueRepresentation`.

## MyTree integration

A future MyTree importer should retain this representation metadata while creating Source Acquisition structures such as `Source`, `Mention`, and `Claim`.

If a scan is later downloaded and independently transcribed, that transcription should be stored as a separate representation such as `verbatim_transcription` rather than replacing the provider/indexer rendering.

This separation allows downstream name processing and candidate generation to use normalized/indexed values as search signals while preventing spelling differences between an original-language transcription and an indexer rendering from being treated automatically as a historical contradiction.
