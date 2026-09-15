<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class IndexCatalogLocality implements JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $providerPlaceId = null,
        public ?string $county = null,
        public ?string $regionCode = null,
        public ?string $regionName = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Catalog locality name cannot be empty.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_place_id' => $this->providerPlaceId,
            'name' => $this->name,
            'county' => $this->county,
            'region_code' => $this->regionCode,
            'region_name' => $this->regionName,
        ];
    }
}
