<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Provider\GenetekaProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Writer\JsonlWriter;
use Psr\Http\Message\RequestInterface;

final class GenetekaProviderTest extends TestCase
{
    public function testFluentAcquisitionMapsCanonicalTypesAndFormFields(): void
    {
        $body = json_encode(
            ['recordsTotal' => '120', 'recordsFiltered' => '0', 'data' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $body));
        $dir = $this->tmp . '/gen-query';
        $writer = new JsonlWriter($dir . '/records.jsonl', false);
        $provider = $this->provider($http, $dir);

        $provider
            ->acquisition()
            ->region('06mp')
            ->parish('4812', 'Imbramowice')
            ->recordType(RecordType::Birth)
            ->person('Gajda', 'Józef')
            ->secondPerson('Nowak', 'Anna')
            ->years(1853, 1860)
            ->exact()
            ->excludeParents()
            ->formParameter('custom_future_field', 'x')
            ->acquire($writer);
        $writer->close();

        self::assertSame(1, $http->calls, 'recordsFiltered=0 must stop pagination after the first request.');
        $query = $this->query($http->urls[0]);
        self::assertSame('B', $query['bdm'] ?? null);
        self::assertSame('06mp', $query['w'] ?? null);
        self::assertSame('4812', $query['rid'] ?? null);
        self::assertSame('Gajda', $query['search_lastname'] ?? null);
        self::assertSame('Józef', $query['search_name'] ?? null);
        self::assertSame('Nowak', $query['search_lastname2'] ?? null);
        self::assertSame('Anna', $query['search_name2'] ?? null);
        self::assertSame('1853', $query['from_date'] ?? null);
        self::assertSame('1860', $query['to_date'] ?? null);
        self::assertSame('1', $query['exac'] ?? null);
        self::assertSame('1', $query['parents'] ?? null);
        self::assertSame('x', $query['custom_future_field'] ?? null);
    }

    public function testFluentBuilderDoesNotMutateBaseQuery(): void
    {
        $provider = $this->provider(new FakeHttpClient(
            fn (RequestInterface $request): Response => new Response(200, [], '{}'),
        ), $this->tmp . '/immutable');

        $base = $provider
            ->acquisition()
            ->region('06mp')
            ->parish('4812')
            ->recordType(RecordType::Birth);
        $filtered = $base->years(1853, 1860)->person('Gajda', 'Józef');

        self::assertSame([], $base->configuration()['form_parameters']);
        self::assertSame([
            'from_date' => 1853,
            'search_lastname' => 'Gajda',
            'search_name' => 'Józef',
            'to_date' => 1860,
        ], $filtered->configuration()['form_parameters']);
    }

    public function testDifferentQueriesUseIndependentCacheAndCheckpointFingerprints(): void
    {
        $body = json_encode(
            ['recordsTotal' => '0', 'recordsFiltered' => '0', 'data' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $body));
        $dir = $this->tmp . '/gen-cache';
        $provider = $this->provider($http, $dir);

        foreach ([1853, 1853, 1854] as $i => $year) {
            $writer = new JsonlWriter($dir . "/records-$i.jsonl", false);
            $provider
                ->acquisition()
                ->region('06mp')
                ->parish('4812')
                ->recordType(RecordType::Birth)
                ->years($year, $year)
                ->acquire($writer);
            $writer->close();
        }

        self::assertSame(2, $http->calls, 'Same query should resume; a different year range must use a different fingerprint.');
    }

    public function testMapsBirthRecordAndResumesFromCheckpoint(): void
    {
        $body = json_encode(
            ['recordsTotal' => '1', 'recordsFiltered' => '1', 'data' => [$this->birthRow()]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $body));
        $dir = $this->tmp . '/gen';
        $writer = new JsonlWriter($dir . '/records.jsonl', false);
        $provider = $this->provider($http, $dir);
        $query = $provider
            ->acquisition()
            ->region('06mp')
            ->parish('4812', 'Imbramowice')
            ->recordType(RecordType::Birth);

        $stats = $query->acquire($writer);
        $writer->close();

        self::assertSame(1, $stats->records);
        self::assertSame(1, $http->calls);
        self::assertStringContainsString('bdm=B', $http->urls[0]);

        $record = json_decode((string) file_get_contents($dir . '/records.jsonl'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('12309516', $record['provider_record_id'] ?? null);
        self::assertSame('Dzjurbjel', $record['fields']['person']['mother_surname_raw'] ?? null);
        self::assertSame('indexer_rendering', $record['representation']['kind'] ?? null);
        self::assertSame('karpecki.lukasz', $record['representation']['producer']['indexer_id'] ?? null);
        self::assertFalse($record['representation']['original_document_wording_asserted'] ?? true);
        self::assertNotEmpty($record['provenance']['query_fingerprint'] ?? null);

        $resumeWriter = new JsonlWriter($dir . '/records.jsonl', true);
        $query->acquire($resumeWriter);
        $resumeWriter->close();

        self::assertSame(1, $http->calls, 'Resume should use checkpoint and make no extra request.');
    }

    public function testReservedTransportParametersCannotBeOverridden(): void
    {
        $provider = $this->provider(new FakeHttpClient(
            fn (RequestInterface $request): Response => new Response(200, [], '{}'),
        ), $this->tmp . '/reserved');

        $this->expectException(InvalidArgumentException::class);
        $provider->acquisition()->formParameter('rid', 'other');
    }

    public function testDiscoversRecordSpecificIdsAndNonContinuousYearRanges(): void
    {
        $pages = [
            'B' => $this->availabilityHtml('B', '4257', ['1645', '1654', '1656', '1658-1862', '1868-1907']),
            'S' => $this->availabilityHtml('S', '10552', ['1701-1750']),
            'D' => $this->availabilityHtml('D', '8629', ['1800', '1802-1810']),
        ];
        $http = new FakeHttpClient(function (RequestInterface $request) use ($pages): Response {
            $query = $this->query((string) $request->getUri());
            $type = (string) ($query['bdm'] ?? '');
            return new Response(200, [], $pages[$type] ?? '');
        });
        $provider = $this->provider($http, $this->tmp . '/availability');

        $availability = $provider->discoverAvailability('10pl', RecordType::Birth, '4257');

        self::assertCount(3, $availability);
        self::assertSame(3, $http->calls);
        self::assertSame('4257', $availability[0]->providerParishId);
        self::assertSame('10552', $availability[1]->providerParishId);
        self::assertSame('8629', $availability[2]->providerParishId);
        self::assertSame([[1645, 1645], [1654, 1654], [1656, 1656], [1658, 1862], [1868, 1907]], array_map(
            static fn ($range): array => [$range->from, $range->to],
            $availability[0]->yearRanges,
        ));

        $provider->discoverAvailability('10pl', RecordType::Birth, '4257');
        self::assertSame(3, $http->calls, 'Availability pages should be reused from raw cache.');
    }

    private function provider(FakeHttpClient $http, string $dir): GenetekaProvider
    {
        return new GenetekaProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );
    }

    /** @return array<string,string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return array_map(static fn (mixed $value): string => (string) $value, $query);
    }

    /** @param list<string> $ranges */
    private function availabilityHtml(string $activeType, string $activeRid, array $ranges): string
    {
        return '<html><body><a href="help">Jak indeksować</a><div class="years">'
            . implode('<br>', $ranges)
            . '</div>'
            . '<a href="?op=gt&amp;bdm=B&amp;w=10pl&amp;rid=4257">Urodzenia</a>'
            . '<a href="?op=gt&amp;bdm=S&amp;w=10pl&amp;rid=10552">Małżeństwa</a>'
            . '<a href="?op=gt&amp;bdm=D&amp;w=10pl&amp;rid=8629">Zgony</a>'
            . '<span data-active="' . $activeType . '" data-rid="' . $activeRid . '"></span>'
            . '</body></html>';
    }

    /** @return list<string> */
    private function birthRow(): array
    {
        return [
            '1913',
            '',
            'Anna ',
            'Tiliszczak',
            'Michał',
            'Melania',
            'Dzjurbjel',
            'Imbramowice',
            'Imbramowice',
            $this->stuff(),
        ];
    }

    private function stuff(): string
    {
        return '<img src="images/i.png" title="Uwagi: Rodzice ojca: Ilja i Tacijanna Tiliszczak. &#013;Data urodzenia: 14.08.1913 r. "><a href="http://www.przemysl.ap.gov.pl/" target="_blank"><img src="images/z.png" title="Miejsce przechowywania ksiąg"></a><a target="_blank" href="https://www.genealodzy.pl/user.php?op=userinfo&amp;uname=karpecki.lukasz"><img src="images/a.png"></a><a target="doc" href="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/18065584"><img src="images/s.png"></a><a href="fix.php?gid=12309516&amp;bdm=B&amp;w=06mp&amp;rid=16033"><img src="images/fix.png"></a>';
    }
}
