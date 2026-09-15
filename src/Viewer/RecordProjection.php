<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

final readonly class RecordProjection
{
    /** @param array<string,mixed> $record */
    public function __construct(
        public array $record,
        public string $type,
        public ?int $year,
        public string $person,
        public string $place,
        public string $related,
        public ?int $similarity,
        public bool $hasScan,
        public string $providerRecordId,
        public string $searchText,
    ) {
    }
}
