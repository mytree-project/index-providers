<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Contracts;

use MyTree\IndexProviders\Domain\IndexCatalogUnit;

interface IndexCatalogDiscoveryInterface
{
    /** @return list<IndexCatalogUnit> */
    public function listCatalogUnits(bool $refresh = false): array;
}
