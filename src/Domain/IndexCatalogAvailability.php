<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class IndexCatalogAvailability implements JsonSerializable
{
    /** @param list<YearRange> $yearRanges */
    public function __construct(
        public string $recordType,
        public array $yearRanges,
        public ?string $rawTypeLabel = null,
        public ?int $recordsCount = null,
    ) {
        if (trim($recordType) === '') {
            throw new InvalidArgumentException('Catalog availability record type cannot be empty.');
        }
        foreach ($yearRanges as $range) {
            if (!$range instanceof YearRange) {
                throw new InvalidArgumentException('Catalog availability year ranges must contain only YearRange values.');
            }
        }
        if ($recordsCount !== null && $recordsCount < 0) {
            throw new InvalidArgumentException('Catalog availability records count cannot be negative.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'record_type' => $this->recordType,
            'year_ranges' => array_map(
                static fn (YearRange $range): array => $range->jsonSerialize(),
                $this->yearRanges,
            ),
            'raw_type_label' => $this->rawTypeLabel,
            'records_count' => $this->recordsCount,
        ];
    }
}
