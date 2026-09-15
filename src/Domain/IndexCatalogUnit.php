<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class IndexCatalogUnit implements JsonSerializable
{
    /**
     * @param list<IndexCatalogAvailability> $availability
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $provenance
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $provider,
        public string $catalogUnitKey,
        public string $unitKind,
        public ?string $providerUnitId = null,
        public ?IndexCatalogLocality $locality = null,
        public ?string $unitName = null,
        public ?string $denomination = null,
        public array $availability = [],
        public array $raw = [],
        public array $provenance = [],
        public array $metadata = [],
    ) {
        if (trim($provider) === '') {
            throw new InvalidArgumentException('Catalog unit provider cannot be empty.');
        }
        if (trim($catalogUnitKey) === '') {
            throw new InvalidArgumentException('Catalog unit key cannot be empty.');
        }
        if (trim($unitKind) === '') {
            throw new InvalidArgumentException('Catalog unit kind cannot be empty.');
        }
        foreach ($availability as $item) {
            if (!$item instanceof IndexCatalogAvailability) {
                throw new InvalidArgumentException('Catalog unit availability must contain only IndexCatalogAvailability values.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 'mytree.index-catalog-unit.v1',
            'provider' => $this->provider,
            'catalog_unit_key' => $this->catalogUnitKey,
            'provider_unit_id' => $this->providerUnitId,
            'locality' => $this->locality?->jsonSerialize(),
            'unit_name' => $this->unitName,
            'unit_kind' => $this->unitKind,
            'denomination' => $this->denomination,
            'availability' => array_map(
                static fn (IndexCatalogAvailability $item): array => $item->jsonSerialize(),
                $this->availability,
            ),
            'raw' => $this->raw,
            'provenance' => $this->provenance,
            'metadata' => $this->metadata,
        ];
    }
}
