<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Provider\GenetekaProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Writer\JsonlWriter;
use Psr\Http\Message\RequestInterface;

final class Psr18ProviderRequestTest extends TestCase
{
    public function testGenetekaBuildsExpectedGetRequestThroughPsr18Client(): void
    {
        $body = json_encode(
            ['recordsTotal' => '0', 'recordsFiltered' => '0', 'data' => []],
            JSON_THROW_ON_ERROR,
        );
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): Response => new Response(200, [], $body),
        );
        $dir = $this->tmp . '/psr-request';
        $provider = new GenetekaProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );
        $writer = new JsonlWriter($dir . '/records.jsonl', false);

        $provider
            ->acquisition()
            ->region('06mp')
            ->parish('4812')
            ->recordType(RecordType::Birth)
            ->acquire($writer);
        $writer->close();

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
        self::assertSame('https://geneteka.genealodzy.pl/index.php', $request->getHeaderLine('Referer'));
        self::assertStringContainsString('application/json', $request->getHeaderLine('Accept'));
    }
}
