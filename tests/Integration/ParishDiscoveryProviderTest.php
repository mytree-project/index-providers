<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Provider\GenetekaProvider;
use MyTree\IndexProviders\Provider\WolynMetrykiProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

final class ParishDiscoveryProviderTest extends TestCase
{
    public function testGenetekaDiscoversRegionsAndParishes(): void
    {
        $html = $this->fixture('geneteka_parishes.html');
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $html));
        $dir = $this->tmp . '/gen-discovery';
        $provider = new GenetekaProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );

        $regions = $provider->listRegions();
        $parishes = $provider->listParishes('06mp');

        self::assertCount(2, $regions);
        self::assertSame('06mp', $regions[0]['code'] ?? null);
        self::assertCount(2, $parishes);
        self::assertSame('4812', $parishes[0]->providerParishId ?? null);
    }

    public function testWolynDiscoversAvailableParishes(): void
    {
        $html = $this->fixture('wolyn_content.html');
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $html));
        $dir = $this->tmp . '/wolyn-discovery';
        $provider = new WolynMetrykiProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );

        self::assertCount(2, $provider->listParishes());
    }
}
