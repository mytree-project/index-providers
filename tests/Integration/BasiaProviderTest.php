<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Http\RetryingHttpClient;
use MyTree\IndexProviders\Provider\BasiaIncompleteResponseException;
use MyTree\IndexProviders\Provider\BasiaProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\CollectingRecordWriter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BasiaProviderTest extends TestCase
{
    public function testBoundedSearchPostsFormAndMapsProviderNeutralRecords(): void
    {
        $fixture = $this->fixture('basia/mixed.html');
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200, [], $fixture),
        );
        [$provider, $checkpoints, $rawStore] = $this->provider($http);
        $writer = new CollectingRecordWriter();
        $search = $provider->search()
            ->person('Kowalski', 'Jan')
            ->years(1800, 1905)
            ->place('Poznań', 15)
            ->recordType(RecordType::Banns)
            ->similarity(70)
            ->sex('male')
            ->relation('child')
            ->unitType('catholic');

        $stats = $search->acquire($writer);

        self::assertSame(1, $stats->requests);
        self::assertSame(6, $stats->records);
        self::assertSame(1, $stats->cacheWrites);
        self::assertSame(1, $http->calls);
        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame('https://basia.famula.pl/', (string) $http->requests[0]->getUri());
        self::assertSame('application/x-www-form-urlencoded', $http->requests[0]->getHeaderLine('Content-Type'));

        parse_str((string) $http->requests[0]->getBody(), $form);
        self::assertSame('Kowalski', $form['lname0']);
        self::assertSame('Jan', $form['fname0']);
        self::assertSame('d', $form['type_record']);
        self::assertSame('kat', $form['type_unit']);
        self::assertSame('m', $form['sex0']);
        self::assertSame('child', $form['type0']);
        self::assertSame('Poznań', $form['placename']);
        self::assertSame('15', $form['distance']);
        self::assertSame('1800', $form['od']);
        self::assertSame('1905', $form['do']);

        self::assertCount(6, $writer->records);
        $birth = $writer->records[0];
        self::assertSame('basia', $birth->provider);
        self::assertSame('101', $birth->providerRecordId);
        self::assertSame('birth', $birth->recordType);
        self::assertNull($birth->parish, 'BASIA unit/locality must not be coerced into a parish.');
        self::assertSame(1815, $birth->year);
        self::assertSame('akt urodzenia/chrztu', $birth->raw['record_type_label']);
        self::assertSame('a', $birth->raw['record_type_code']);
        self::assertSame('https://www.szukajwarchiwach.gov.pl/jednostka/1#scan12', $birth->fields['scan_url']);
        self::assertSame('https://basia.famula.pl/record/token101', $birth->provenance['source_url']);
        self::assertSame('indexer_rendering', $birth->representation?->kind);
        self::assertFalse($birth->representation?->originalDocumentWordingAsserted ?? true);

        $unknown = $writer->records[5];
        self::assertStringStartsWith('provider:basia:', $unknown->recordType);
        self::assertNotSame('other', $unknown->recordType);
        self::assertSame('księga ludności', $unknown->raw['record_type_label']);

        $fingerprint = $search->fingerprint();
        self::assertTrue($checkpoints->get("basia:query:$fingerprint:complete"));
        $metadata = $rawStore->metadata('basia', 'query_' . $fingerprint, 'html');
        self::assertIsArray($metadata);
        self::assertTrue($metadata['complete']);
        self::assertSame('POST', $metadata['request_method']);
    }

    public function testCompletedQueryIsSkippedWithoutNetworkIo(): void
    {
        $fixture = $this->fixture('basia/empty.html');
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200, [], $fixture),
        );
        [$provider] = $this->provider($http);
        $search = $provider->search()->surname('Nobody')->recordType(RecordType::Death);

        $first = $search->acquire(new CollectingRecordWriter());
        $second = $search->acquire(new CollectingRecordWriter());

        self::assertSame(1, $first->requests);
        self::assertSame(0, $first->records);
        self::assertSame(1, $http->calls);
        self::assertSame(0, $second->requests);
        self::assertSame(1, $second->skippedUnits);
        self::assertSame(1, $http->calls);
    }

    public function testIncompleteHttp200IsNotCachedOrCheckpointed(): void
    {
        $fixture = $this->fixture('basia/incomplete.html');
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200, [], $fixture),
        );
        [$provider, $checkpoints, $rawStore] = $this->provider($http);
        $search = $provider->search()->surname('BroadQuery');
        $fingerprint = $search->fingerprint();

        try {
            $search->acquire(new CollectingRecordWriter());
            self::fail('Expected incomplete BASIA response to fail.');
        } catch (BasiaIncompleteResponseException) {
            self::assertNull($rawStore->get('basia', 'query_' . $fingerprint, 'html'));
            self::assertNull($checkpoints->get("basia:query:$fingerprint:complete"));
        }
    }

    public function testPostSearchComposesWithRetryPolicyWithoutRealSleep(): void
    {
        $fixture = $this->fixture('basia/empty.html');
        $responses = [new Response(503), new Response(200, [], $fixture)];
        $transport = new FakeHttpClient(
            static function (RequestInterface $request) use (&$responses): ResponseInterface {
                $response = array_shift($responses);
                self::assertInstanceOf(ResponseInterface::class, $response);

                return $response;
            },
        );
        $sleeps = [];
        $retrying = new RetryingHttpClient(
            $transport,
            maxAttempts: 2,
            sleeper: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            retryableMethods: ['POST'],
        );
        [$provider] = $this->provider($retrying);

        $stats = $provider->search()->surname('Retry')->acquire(new CollectingRecordWriter());

        self::assertSame(1, $stats->requests, 'Provider issues one logical request; transport owns retries.');
        self::assertSame(2, $transport->calls);
        self::assertSame([2], $sleeps);
        self::assertSame('POST', $transport->requests[0]->getMethod());
        self::assertSame((string) $transport->requests[0]->getBody(), (string) $transport->requests[1]->getBody());
    }

    /**
     * @return array{0:BasiaProvider,1:JsonCheckpointStore,2:RawResponseStore}
     */
    private function provider(\Psr\Http\Client\ClientInterface $http): array
    {
        $checkpoints = new JsonCheckpointStore($this->tmp . '/state/checkpoints.json');
        $rawStore = new RawResponseStore($this->tmp . '/raw');

        return [
            new BasiaProvider($http, $checkpoints, $rawStore, new RateLimiter(0)),
            $checkpoints,
            $rawStore,
        ];
    }
}
