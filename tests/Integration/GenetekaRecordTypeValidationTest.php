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
use RuntimeException;

final class GenetekaRecordTypeValidationTest extends TestCase
{
    public function testAcquisitionRejectsUnsupportedCanonicalTypesBeforeNetworkIo(): void
    {
        $http = new FakeHttpClient(static fn (RequestInterface $request): Response => new Response(200, [], '{}'));
        $provider = $this->provider($http, $this->tmp . '/acquisition-validation');

        foreach ([RecordType::Banns, RecordType::Other, RecordType::ParishCensus] as $type) {
            try {
                $provider->acquisition()->recordType($type);
                self::fail('Unsupported Geneteka record type was accepted: ' . $type->value);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($type->value, $exception->getMessage());
                self::assertStringContainsString('birth, marriage, death', $exception->getMessage());
            }
        }

        self::assertSame(0, $http->calls);
    }

    public function testAvailabilityRejectsUnsupportedCanonicalTypesBeforeNetworkIo(): void
    {
        $http = new FakeHttpClient(static fn (RequestInterface $request): Response => new Response(200, [], ''));
        $provider = $this->provider($http, $this->tmp . '/availability-validation');

        foreach ([RecordType::Banns, RecordType::Other, RecordType::ParishCensus] as $type) {
            try {
                $provider->discoverAvailability('10pl', $type, '4257');
                self::fail('Unsupported Geneteka availability type was accepted: ' . $type->value);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($type->value, $exception->getMessage());
            }
        }

        self::assertSame(0, $http->calls);
    }

    public function testExistingGenetekaRecordTypeCodesRemainUnchanged(): void
    {
        $body = json_encode(
            ['recordsTotal' => '0', 'recordsFiltered' => '0', 'data' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $http = new FakeHttpClient(static fn (RequestInterface $request): Response => new Response(200, [], $body));
        $provider = $this->provider($http, $this->tmp . '/existing-types');

        foreach ([
            [RecordType::Birth, 'B'],
            [RecordType::Marriage, 'S'],
            [RecordType::Death, 'D'],
        ] as $index => [$type, $providerCode]) {
            $writer = new JsonlWriter($this->tmp . "/existing-types/records-$index.jsonl", false);
            $provider
                ->acquisition()
                ->region('06mp')
                ->parish('4812')
                ->recordType($type)
                ->acquire($writer);
            $writer->close();

            parse_str((string) parse_url($http->urls[$index], PHP_URL_QUERY), $query);
            self::assertSame($providerCode, $query['bdm'] ?? null);
        }

        self::assertSame(3, $http->calls);
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
}
