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

    public function testUnknownSectionIsPreservedAsProviderQualifiedOpaqueRecord(): void
    {
        $html = $this->fixture('wolyn_unknown_section.html');
        $http = new FakeHttpClient(fn (RequestInterface $request): Response => new Response(200, [], $html));
        $dir = $this->tmp . '/wolyn-unknown';
        $writer = new JsonlWriter($dir . '/records.jsonl', false);
        $provider = new WolynMetrykiProvider(
            $http,
            new JsonCheckpointStore($dir . '/state.json'),
            new RawResponseStore($dir . '/raw'),
            new RateLimiter(0),
        );

        $stats = $provider->acquire('Szumsk', 1835, 1835, $writer);
        $writer->close();

        self::assertSame(1, $stats->records);
        self::assertSame(1, $http->calls);

        $lines = file($dir . '/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('provider:wolyn-metryki:', $record['record_type'] ?? '');
        self::assertNull($record['year'] ?? null, 'Unknown provider table columns must not be guessed as event-year semantics.');
        self::assertSame('Szumsk', $record['parish'] ?? null);
        self::assertSame('Potwierdzenia', $record['raw']['section_title'] ?? null);
        self::assertSame(['Kolumna A', 'Kolumna B', 'Odnośnik'], $record['raw']['headers'] ?? null);
        self::assertSame(['wartość źródłowa', '1835?', 'skan 7'], $record['raw']['cells'] ?? null);
        self::assertSame([[], [], ['https://example.test/unknown-scan']], $record['raw']['hrefs'] ?? null);
        self::assertSame('Potwierdzenia', $record['fields']['provider_table']['section_title_raw'] ?? null);
        self::assertSame(1835, $record['provenance']['requested_year'] ?? null);
        self::assertSame('indexer_rendering', $record['representation']['kind'] ?? null);
        self::assertFalse($record['representation']['original_document_wording_asserted'] ?? true);
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
