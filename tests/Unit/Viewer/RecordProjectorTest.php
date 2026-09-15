<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit\Viewer;

use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Viewer\RecordProjector;

final class RecordProjectorTest extends TestCase
{
    public function testProjectsBasiaRecordAndSearchesPolishValuesWithoutQueryContamination(): void
    {
        $record = $this->baseRecord('basia-1', 'birth', 1863, [
            'person' => ['name_raw' => 'Łucja'],
            'other_persons_raw' => ['Walenty Wiśniewski'],
            'place_raw' => 'Kozielsko',
            'similarity_percent' => 100,
            'scan_url' => 'https://example.test/scan/1',
        ]);
        $record['provenance'] = ['query' => ['surname' => 'Completely Different']];

        $projector = new RecordProjector();
        $projection = $projector->project($record);

        self::assertSame('Łucja', $projection->person);
        self::assertSame('Kozielsko', $projection->place);
        self::assertStringContainsString('Walenty Wiśniewski', $projection->related);
        self::assertSame(100, $projection->similarity);
        self::assertTrue($projection->hasScan);
        self::assertStringContainsString($projector->fold('WIŚNIEWSKI'), $projection->searchText);
        self::assertStringNotContainsString($projector->fold('Completely Different'), $projection->searchText);
    }

    public function testProjectsGenetekaMarriage(): void
    {
        $projection = (new RecordProjector())->project($this->baseRecord('g-1', 'marriage', 1888, [
            'groom' => ['given_names_raw' => 'Jan', 'surname_raw' => 'Kowalski', 'parents_raw' => 'Piotr i Maria'],
            'bride' => ['given_names_raw' => 'Anna', 'surname_raw' => 'Nowak', 'parents_raw' => 'Józef i Zofia'],
            'parish_raw' => 'Imbramowice',
        ]));

        self::assertSame('Jan Kowalski × Anna Nowak', $projection->person);
        self::assertSame('Imbramowice', $projection->place);
        self::assertStringContainsString('Piotr i Maria', $projection->related);
    }

    public function testProjectsWolynBirth(): void
    {
        $projection = (new RecordProjector())->project($this->baseRecord('w-1', 'birth', 1835, [
            'child' => ['given_names_raw' => 'Michał', 'surname_raw' => 'Gajda'],
            'place_raw' => 'Szumsk',
            'father_given_names_raw' => 'Jan',
            'mother_given_names_raw' => 'Marianna',
            'source_locator' => ['scan_number_raw' => '42'],
        ]));

        self::assertSame('Michał Gajda', $projection->person);
        self::assertSame('Szumsk', $projection->place);
        self::assertTrue($projection->hasScan);
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function baseRecord(string $id, string $type, int $year, array $fields): array
    {
        return [
            'schema' => 'mytree.external-index-record.v1',
            'provider' => 'fixture',
            'provider_record_id' => $id,
            'record_type' => $type,
            'parish' => null,
            'year' => $year,
            'fields' => $fields,
            'raw' => [],
            'provenance' => [],
            'representation' => null,
        ];
    }
}
