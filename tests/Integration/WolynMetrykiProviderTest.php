<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use MyTree\IndexProviders\Provider\WolynMetrykiProvider;
use MyTree\IndexProviders\Storage\JsonCheckpointStore;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Tests\Support\FakeHttpClient;
use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Writer\JsonlWriter;
use Psr\Http\Message\RequestInterface;

final class WolynMetrykiProviderTest extends TestCase
{
    public function testMapsAllRecordTypesAndPreservesSourceFaithfulData(): void
    {
        $html = $this->fixture('wolyn_small.html');
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $html));
        $dir = $this->tmp . '/wolyn';
        $writer = new JsonlWriter($dir . '/records.jsonl', false);
        $provider = new WolynMetrykiProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );

        $stats = $provider->acquire('Szumsk', 1835, 1835, $writer);
        $writer->close();

        self::assertSame(4, $stats->records);
        self::assertSame(1, $http->calls);

        $birth = $this->birthRecord($dir . '/records.jsonl');
        self::assertSame('Joanna', $birth['fields']['child']['given_names_raw'] ?? null);
        self::assertSame('https://example.test/birth', $birth['fields']['source_locator']['scan_url'] ?? null);
        self::assertSame('indexer_rendering', $birth['representation']['kind'] ?? null);
        self::assertTrue($birth['representation']['verbatim_from_provider'] ?? false);
        self::assertSame('DM', $birth['representation']['producer']['indexer_id'] ?? null);
        self::assertFalse($birth['representation']['original_document_wording_asserted'] ?? true);
    }

    /** @return array<string,mixed> */
    private function birthRecord(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($record['record_type'] ?? null) === 'birth') {
                return $record;
            }
        }

        self::fail('Birth record was not written.');
    }
}
