<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Contracts\IndexCatalogDiscoveryInterface;
use MyTree\IndexProviders\Provider\BasiaIncompleteResponseException;
use MyTree\IndexProviders\Provider\BasiaProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BasiaCatalogDiscoveryTest extends TestCase
{
    public function testCatalogDiscoveryUsesGeneralizedContractAndCache(): void
    {
        $fixture = $this->fixture('basia/catalog.html');
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200, [], $fixture),
        );
        $rawStore = new RawResponseStore($this->tmp . '/raw');
        $provider = new BasiaProvider(
            $http,
            new JsonCheckpointStore($this->tmp . '/state/checkpoints.json'),
            $rawStore,
            new RateLimiter(0),
        );

        self::assertInstanceOf(IndexCatalogDiscoveryInterface::class, $provider);

        $first = $provider->listCatalogUnits();
        $second = $provider->listCatalogUnits();
        $refreshed = $provider->listCatalogUnits(true);

        self::assertCount(6, $first);
        self::assertCount(6, $second);
        self::assertCount(6, $refreshed);
        self::assertSame(2, $http->calls);
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame(
            'https://basia.famula.pl/content-all.php?lang=pl',
            (string) $http->requests[0]->getUri(),
        );

        $firstUnit = $first[0]->jsonSerialize();
        self::assertSame('mytree.index-catalog-unit.v1', $firstUnit['schema']);
        self::assertSame('basia', $firstUnit['provider']);
        self::assertSame('Blizanów', $firstUnit['locality']['name']);
        self::assertSame('parish', $firstUnit['unit_kind']);
        self::assertSame('evangelical', $firstUnit['denomination']);
        self::assertSame(13347, $firstUnit['metadata']['locality_total_records']);
        self::assertSame(['Jadwiga Kaleta', 'Joanna Włoch'], $firstUnit['metadata']['indexers']);
        self::assertSame('GET', $firstUnit['provenance']['request_method']);

        $civil = $first[2]->jsonSerialize();
        self::assertSame('civil_registry', $civil['unit_kind']);
        self::assertNotSame('parish', $civil['unit_kind']);

        $unknown = $first[4]->jsonSerialize();
        self::assertStringStartsWith('provider:basia:', $unknown['unit_kind']);
        self::assertNotSame('other', $unknown['unit_kind']);

        $metadata = $rawStore->metadata('basia', 'catalog_pl', 'html');
        self::assertIsArray($metadata);
        self::assertTrue($metadata['complete']);
        self::assertSame('index_catalog', $metadata['purpose']);
        self::assertSame(6, $metadata['catalog_unit_count']);
    }

    public function testIncompleteCatalogIsNotCachedAsSuccessful(): void
    {
        $fixture = $this->fixture('basia/catalog-incomplete.html');
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200, [], $fixture),
        );
        $rawStore = new RawResponseStore($this->tmp . '/raw');
        $provider = new BasiaProvider(
            $http,
            new JsonCheckpointStore($this->tmp . '/state/checkpoints.json'),
            $rawStore,
            new RateLimiter(0),
        );

        try {
            $provider->listCatalogUnits();
            self::fail('Expected incomplete BASIA catalog response to fail.');
        } catch (BasiaIncompleteResponseException) {
            self::assertNull($rawStore->get('basia', 'catalog_pl', 'html'));
            self::assertNull($rawStore->metadata('basia', 'catalog_pl', 'html'));
        }
    }
}
