<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use MyTree\IndexProviders\Domain\AvailableParish;
use MyTree\IndexProviders\Domain\IndexCatalogAvailability;
use MyTree\IndexProviders\Domain\IndexCatalogLocality;
use MyTree\IndexProviders\Domain\IndexCatalogUnit;
use MyTree\IndexProviders\Domain\YearRange;
use MyTree\IndexProviders\Tests\TestCase;

final class IndexCatalogUnitTest extends TestCase
{
    public function testSerializesGeneralizedCatalogContract(): void
    {
        $unit = new IndexCatalogUnit(
            provider: 'basia',
            catalogUnitKey: 'basia:abc123',
            unitKind: 'parish',
            providerUnitId: null,
            locality: new IndexCatalogLocality(
                name: 'Poznań',
                county: 'poznański',
            ),
            unitName: 'Parafia katolicka',
            denomination: 'roman_catholic',
            availability: [
                new IndexCatalogAvailability(
                    recordType: 'birth',
                    yearRanges: [new YearRange(1800, 1810), new YearRange(1815, 1820)],
                    rawTypeLabel: 'chrzty',
                ),
            ],
            raw: ['unit_label' => 'Parafia katolicka'],
            provenance: ['source_url' => 'https://basia.famula.pl/content-all.php?lang=pl'],
            metadata: ['locality_total_records' => 123],
        );

        self::assertSame([
            'schema' => 'mytree.index-catalog-unit.v1',
            'provider' => 'basia',
            'catalog_unit_key' => 'basia:abc123',
            'provider_unit_id' => null,
            'locality' => [
                'provider_place_id' => null,
                'name' => 'Poznań',
                'county' => 'poznański',
                'region_code' => null,
                'region_name' => null,
            ],
            'unit_name' => 'Parafia katolicka',
            'unit_kind' => 'parish',
            'denomination' => 'roman_catholic',
            'availability' => [
                [
                    'record_type' => 'birth',
                    'year_ranges' => [
                        ['from' => 1800, 'to' => 1810],
                        ['from' => 1815, 'to' => 1820],
                    ],
                    'raw_type_label' => 'chrzty',
                    'records_count' => null,
                ],
            ],
            'raw' => ['unit_label' => 'Parafia katolicka'],
            'provenance' => ['source_url' => 'https://basia.famula.pl/content-all.php?lang=pl'],
            'metadata' => ['locality_total_records' => 123],
        ], $unit->jsonSerialize());
    }

    public function testLegacyAvailableParishContractRemainsVersionOne(): void
    {
        $parish = new AvailableParish('geneteka', 'Imbramowice', '4812', '06mp', 'Małopolskie');

        self::assertSame('mytree.available-parish.v1', $parish->toArray()['schema']);
        self::assertArrayNotHasKey('unit_kind', $parish->toArray());
    }
}
