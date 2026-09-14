<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Provider\BasiaProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BasiaSearchTest extends TestCase
{
    public function testQueryIsImmutableAndFingerprintIsDeterministic(): void
    {
        $provider = $this->provider();
        $base = $provider->search()->surname('Kowalski');
        $first = $base
            ->givenName('Jan')
            ->years(1880, 1890)
            ->recordType(RecordType::Birth)
            ->similarity(75);
        $second = $provider->search()
            ->similarity(75)
            ->recordType(RecordType::Birth)
            ->years(1880, 1890)
            ->person('Kowalski', 'Jan');

        self::assertNull($base->configuration()['given_name']);
        self::assertSame($first->configuration(), $second->configuration());
        self::assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testForceDoesNotChangeFingerprint(): void
    {
        $search = $this->provider()->search()->surname('Nowak')->recordType(RecordType::Marriage);

        self::assertSame($search->fingerprint(), $search->force()->fingerprint());
    }

    public function testBasiaAcceptsCanonicalSupportedRecordTypes(): void
    {
        $provider = $this->provider();

        foreach ([RecordType::Birth, RecordType::Marriage, RecordType::Death, RecordType::Banns, RecordType::Other] as $type) {
            self::assertSame($type->value, $provider->search()->surname('Nowak')->recordType($type)->configuration()['record_type']);
        }
    }

    public function testBasiaRejectsParishCensusBeforeNetworkIo(): void
    {
        $http = new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200),
        );
        $provider = $this->provider($http);

        try {
            $provider->search()->surname('Nowak')->recordType(RecordType::ParishCensus);
            self::fail('Expected unsupported BASIA record type to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('birth, marriage, death, banns, other', $exception->getMessage());
        }

        self::assertSame(0, $http->calls);
    }

    public function testSearchRequiresAtLeastNameOrPlace(): void
    {
        $search = $this->provider()->search();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least surname, given name or place');
        $search->fingerprint();
    }

    private function provider(?FakeHttpClient $http = null): BasiaProvider
    {
        $http ??= new FakeHttpClient(
            static fn (RequestInterface $request): ResponseInterface => new Response(200),
        );

        return new BasiaProvider(
            $http,
            new JsonCheckpointStore($this->tmp . '/state.json'),
            new RawResponseStore($this->tmp . '/raw'),
            new RateLimiter(0),
        );
    }
}
