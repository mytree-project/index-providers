<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use MyTree\IndexProviders\Provider\BasiaHtmlParser;
use MyTree\IndexProviders\Provider\BasiaIncompleteResponseException;
use MyTree\IndexProviders\Tests\TestCase;
use RuntimeException;

final class BasiaHtmlParserTest extends TestCase
{
    public function testParsesCanonicalTypesRawValuesAndScanLocator(): void
    {
        $result = (new BasiaHtmlParser())->parse($this->fixture('basia/mixed.html'));

        self::assertSame(1.23, $result['search_time_seconds']);
        self::assertCount(6, $result['records']);
        self::assertSame(
            ['birth', 'marriage', 'death', 'banns', 'other'],
            array_slice(array_column($result['records'], 'record_type'), 0, 5),
        );

        $birth = $result['records'][0];
        self::assertSame('101', $birth['record_id']);
        self::assertSame('a', $birth['record_type_code']);
        self::assertSame('akt urodzenia/chrztu', $birth['record_type_label']);
        self::assertSame('Poznań', $birth['place']);
        self::assertSame('par. rzymskokatolicka', $birth['unit_type']);
        self::assertSame(1815, $birth['year']);
        self::assertSame('Jan Kowalski', $birth['name']);
        self::assertSame('Piotr Kowalski', $birth['father']);
        self::assertSame('Anna Nowak', $birth['mother']);
        self::assertSame(['Antoni Kowalski'], $birth['other_persons']);
        self::assertSame('zapis indeksowy', $birth['indexer_comment']);
        self::assertSame('53/1/2', $birth['signature']);
        self::assertSame('53/1/2, skan 12', $birth['scan_label']);
        self::assertSame('https://www.szukajwarchiwach.gov.pl/jednostka/1#scan12', $birth['scan_url']);
        self::assertSame('https://basia.famula.pl/record/token101', $birth['permalink']);
        self::assertSame('Alicja Indeksująca', $birth['indexer']);
    }

    public function testBannsAndProviderDeclaredOtherRemainDistinct(): void
    {
        $records = (new BasiaHtmlParser())->parse($this->fixture('basia/mixed.html'))['records'];

        self::assertSame('banns', $records[3]['record_type']);
        self::assertSame('d', $records[3]['record_type_code']);
        self::assertSame('zapowiedzi', $records[3]['record_type_label']);
        self::assertSame('other', $records[4]['record_type']);
        self::assertSame('z', $records[4]['record_type_code']);
        self::assertSame('inne', $records[4]['record_type_label']);
    }

    public function testUnknownBasiaCategoryUsesOpaqueProviderQualifiedType(): void
    {
        $record = (new BasiaHtmlParser())->parse($this->fixture('basia/mixed.html'))['records'][5];

        self::assertStringStartsWith('provider:basia:', $record['record_type']);
        self::assertNotSame('other', $record['record_type']);
        self::assertSame('księga ludności', $record['record_type_label']);
        self::assertStringStartsWith('unknown:', $record['record_type_code']);
    }

    public function testMarriageAndDeathRelationshipsAreParsed(): void
    {
        $records = (new BasiaHtmlParser())->parse($this->fixture('basia/mixed.html'))['records'];

        self::assertSame('Wiktorya Nitecka', $records[1]['spouse']);
        self::assertSame('Franciszek Kawecki', $records[1]['father']);
        self::assertSame('Marianna', $records[1]['mother']);
        self::assertSame('54 lat', $records[2]['age']);
        self::assertSame('Walenty', $records[2]['spouse']);
    }

    public function testSearchMarkerWithNoResultBoxesIsValidEmptyResult(): void
    {
        $result = (new BasiaHtmlParser())->parse($this->fixture('basia/empty.html'));

        self::assertSame([], $result['records']);
        self::assertSame(0.42, $result['search_time_seconds']);
    }

    public function testMissingSearchMarkerIsIncompleteResponse(): void
    {
        $this->expectException(BasiaIncompleteResponseException::class);
        $this->expectExceptionMessage('search-complete marker');

        (new BasiaHtmlParser())->parse($this->fixture('basia/incomplete.html'));
    }

    public function testMalformedResultStructureIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lbox/rbox');

        (new BasiaHtmlParser())->parse($this->fixture('basia/malformed.html'));
    }
}
