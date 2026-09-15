<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use MyTree\IndexProviders\Domain\YearRange;
use MyTree\IndexProviders\Provider\BasiaCatalogParser;
use MyTree\IndexProviders\Provider\BasiaIncompleteResponseException;
use MyTree\IndexProviders\Tests\TestCase;

final class BasiaCatalogParserTest extends TestCase
{
    public function testParsesLocalitiesUnitsAndDiscontinuousAvailability(): void
    {
        $units = (new BasiaCatalogParser())->parse($this->fixture('basia/catalog.html'));

        self::assertCount(6, $units);

        $evangelical = $units[0];
        self::assertSame('Blizanów', $evangelical['locality_name']);
        self::assertSame('kaliski', $evangelical['county']);
        self::assertSame('Parafia ewangelicka', $evangelical['raw_unit_label']);
        self::assertSame('parish', $evangelical['unit_kind']);
        self::assertSame('evangelical', $evangelical['denomination']);
        self::assertSame(13347, $evangelical['locality_total_records']);
        self::assertSame(['Jadwiga Kaleta', 'Joanna Włoch'], $evangelical['indexers']);

        $birth = $evangelical['availability'][0];
        self::assertSame('birth', $birth['record_type']);
        self::assertSame('chrzty', $birth['raw_type_label']);
        self::assertEquals([
            new YearRange(1832, 1840),
            new YearRange(1844, 1844),
            new YearRange(1852, 1852),
        ], $birth['year_ranges']);

        $catholic = $units[1];
        self::assertSame('roman_catholic', $catholic['denomination']);
        self::assertSame(
            ['birth', 'marriage', 'other', 'banns'],
            array_column($catholic['availability'], 'record_type'),
        );

        $civil = $units[2];
        self::assertSame('civil_registry', $civil['unit_kind']);
        self::assertNull($civil['denomination']);

        $providerOther = $units[3];
        self::assertSame('other', $providerOther['unit_kind']);
        self::assertSame('other', $providerOther['availability'][0]['record_type']);

        $unknown = $units[4];
        self::assertStringStartsWith('provider:basia:', $unknown['unit_kind']);
        self::assertNotSame('other', $unknown['unit_kind']);
        self::assertStringStartsWith('provider:basia:', $unknown['availability'][0]['record_type']);
        self::assertNotSame('other', $unknown['availability'][0]['record_type']);

        $unlabeled = $units[5];
        self::assertSame('Bełchatów', $unlabeled['locality_name']);
        self::assertSame('provider:basia:unlabeled', $unlabeled['unit_kind']);
        self::assertSame([], $unlabeled['availability']);
    }

    public function testRejectsIncompleteCatalog(): void
    {
        $this->expectException(BasiaIncompleteResponseException::class);

        (new BasiaCatalogParser())->parse($this->fixture('basia/catalog-incomplete.html'));
    }
}
