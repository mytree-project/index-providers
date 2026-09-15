<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

final readonly class AcquisitionRun
{
    /**
     * @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $records
     */
    public function __construct(
        public string $directory,
        public array $manifest,
        public array $records,
    ) {
    }
}
