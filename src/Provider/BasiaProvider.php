<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use MyTree\IndexProviders\Contracts\CheckpointStoreInterface;
use MyTree\IndexProviders\Contracts\IndexCatalogDiscoveryInterface;
use MyTree\IndexProviders\Contracts\ProgressReporterInterface;
use MyTree\IndexProviders\Contracts\RecordWriterInterface;
use MyTree\IndexProviders\Domain\AcquisitionStats;
use MyTree\IndexProviders\Domain\ExternalIndexRecord;
use MyTree\IndexProviders\Domain\IndexCatalogUnit;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Domain\ValueRepresentation;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\NullProgressReporter;
use MyTree\IndexProviders\Support\RateLimiter;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

final class BasiaProvider implements IndexCatalogDiscoveryInterface
{
    public const DEFAULT_BASE_URL = 'https://basia.famula.pl';
    public const DEFAULT_MINIMUM_DELAY_MS = 5000;
    public const DEFAULT_TIMEOUT_SECONDS = 200;

    private const YEAR_MIN = 1577;

    /** @var array<string,string> */
    private const SEX_TO_FORM = [
        'any' => 'any',
        'male' => 'm',
        'female' => 'k',
    ];

    /** @var array<string,string> */
    private const RELATION_TO_FORM = [
        'any' => 'any',
        'parent_or_spouse' => 'parent',
        'child' => 'child',
        'other' => 'other',
    ];

    /** @var array<string,string> */
    private const RECORD_TYPE_TO_FORM = [
        'birth' => 'a',
        'marriage' => 'b',
        'death' => 'c',
        'banns' => 'd',
        'other' => 'z',
    ];

    /** @var array<string,string> */
    private const UNIT_TYPE_TO_FORM = [
        'any' => 'any',
        'usc' => 'usc',
        'catholic' => 'kat',
        'evangelical' => 'ewa',
        'other' => 'other',
    ];

    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CheckpointStoreInterface $checkpoints,
        private readonly RawResponseStore $rawStore,
        private readonly RateLimiter $rateLimiter = new RateLimiter(self::DEFAULT_MINIMUM_DELAY_MS),
        private readonly BasiaHtmlParser $parser = new BasiaHtmlParser(),
        private readonly ProgressReporterInterface $progress = new NullProgressReporter(),
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $factory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
    }

    public function search(): BasiaSearch
    {
        return new BasiaSearch($this);
    }

    /** @return list<IndexCatalogUnit> */
    public function listCatalogUnits(bool $refresh = false): array
    {
        return (new BasiaCatalogDiscovery(
            $this->http,
            $this->rawStore,
            $this->rateLimiter,
            $this->progress,
            $this->requestFactory,
            $this->baseUrl,
        ))->listCatalogUnits($refresh);
    }

    /** @internal Executed by BasiaSearch::acquire(). */
    public function executeSearch(BasiaSearch $search, RecordWriterInterface $writer): AcquisitionStats
    {
        $search->assertReady();
        $fingerprint = $search->fingerprint();
        $stats = new AcquisitionStats();
        $checkpointKey = "basia:query:$fingerprint:complete";
        $checkpointMetaKey = "basia:query:$fingerprint:meta";

        if (!$search->isForced() && $this->checkpoints->get($checkpointKey) === true) {
            $stats->skippedUnits++;
            $this->progress->info("BASIA query $fingerprint already completed; skipping.");

            return $stats;
        }

        $cacheKey = 'query_' . $fingerprint;
        $body = !$search->isForced() ? $this->rawStore->get('basia', $cacheKey, 'html') : null;
        $cacheMeta = !$search->isForced() ? $this->rawStore->metadata('basia', $cacheKey, 'html') : null;
        $form = $this->formParameters($search);
        $requestUrl = rtrim($this->baseUrl, '/') . '/';

        if ($body !== null) {
            $this->progress->info("BASIA query $fingerprint: using cached complete response.");
            $retrievedAt = is_array($cacheMeta) && isset($cacheMeta['retrieved_at'])
                ? (string) $cacheMeta['retrieved_at']
                : gmdate(DATE_ATOM);
            $rawPath = $this->rawStore->path('basia', $cacheKey, 'html');
            $rawSha256 = hash('sha256', $body);
            try {
                $parsed = $this->parser->parse($body, $this->baseUrl);
            } catch (BasiaIncompleteResponseException) {
                $body = null;
            }
        }

        if ($body === null) {
            $this->progress->info("BASIA query $fingerprint: submitting bounded search.");
            $this->rateLimiter->beforeRequest();
            $requestBody = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $request = $this->requestFactory
                ->createRequest('POST', $requestUrl)
                ->withHeader('Accept', 'text/html,application/xhtml+xml')
                ->withHeader('Referer', $requestUrl)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streamFactory->createStream($requestBody));

            $response = $this->http->sendRequest($request);
            $stats->requests++;
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("BASIA HTTP {$status}: $requestUrl");
            }

            $body = (string) $response->getBody();
            $retrievedAt = gmdate(DATE_ATOM);
            $rawSha256 = hash('sha256', $body);

            // Parse before writing the reusable cache. An incomplete/changed HTTP 200
            // must never become a successful cache/checkpoint entry.
            $parsed = $this->parser->parse($body, $this->baseUrl);
            $rawPath = $this->rawStore->put('basia', $cacheKey, 'html', $body, [
                'provider' => 'basia',
                'purpose' => 'bounded_search',
                'requested_url' => $requestUrl,
                'request_method' => 'POST',
                'http_status' => $status,
                'retrieved_at' => $retrievedAt,
                'query_fingerprint' => $fingerprint,
                'query' => $search->configuration(),
                'search_time_seconds' => $parsed['search_time_seconds'],
                'complete' => true,
            ]);
            $stats->cacheWrites++;
        }

        foreach ($parsed['records'] as $index => $record) {
            $external = $this->mapRecord(
                $record,
                $search,
                $fingerprint,
                (int) $index,
                $requestUrl,
                $rawPath,
                $rawSha256,
                $retrievedAt,
            );
            $writer->write($external);
            $stats->record($external->recordType);
        }

        $this->checkpoints->set($checkpointMetaKey, [
            'record_count' => count($parsed['records']),
            'query_fingerprint' => $fingerprint,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
        $this->checkpoints->set($checkpointKey, true);

        $this->progress->info('BASIA query ' . $fingerprint . ': ' . count($parsed['records']) . ' records parsed.');

        return $stats;
    }

    /** @return array<string,string> */
    private function formParameters(BasiaSearch $search): array
    {
        $recordType = $search->type();
        if ($recordType === RecordType::ParishCensus) {
            throw new RuntimeException('BASIA does not support parish_census.');
        }
        $recordValue = $recordType === null ? 'any' : (self::RECORD_TYPE_TO_FORM[$recordType->value] ?? null);
        if ($recordValue === null) {
            throw new RuntimeException('Unsupported BASIA record type: ' . $recordType->value . '.');
        }

        $unitValue = self::UNIT_TYPE_TO_FORM[$search->unitTypeValue()] ?? null;
        $sexValue = self::SEX_TO_FORM[$search->sexValue()] ?? null;
        $relationValue = self::RELATION_TO_FORM[$search->relationValue()] ?? null;
        if ($unitValue === null || $sexValue === null || $relationValue === null) {
            throw new RuntimeException('Invalid BASIA query configuration.');
        }

        $place = $search->placeValue();
        $parameters = [
            'fname0' => $search->givenNameValue() ?? '',
            'lname0' => $search->surnameValue() ?? '',
            'sex0' => $sexValue,
            'type0' => $relationValue,
            'sim0' => (string) $search->similarityValue(),
            'p_count' => '1',
            'od' => (string) ($search->fromYearValue() ?? self::YEAR_MIN),
            'do' => (string) ($search->toYearValue() ?? (int) gmdate('Y')),
            'showplaces' => $place !== null ? 'block' : 'none',
            'showtype' => ($recordValue !== 'any' || $unitValue !== 'any') ? 'block' : 'none',
            'showdate' => 'none',
            'type_unit' => $unitValue,
            'type_record' => $recordValue,
            'search_ext' => 'szukaj',
            'search_ext_button' => 'Szukaj',
        ];
        if ($place !== null) {
            $parameters['placename'] = $place;
            $parameters['distance'] = (string) $search->distanceKmValue();
        }

        return $parameters;
    }

    /** @param array<string,mixed> $record */
    private function mapRecord(
        array $record,
        BasiaSearch $search,
        string $queryFingerprint,
        int $resultIndex,
        string $requestUrl,
        string $rawPath,
        string $rawSha256,
        string $retrievedAt,
    ): ExternalIndexRecord {
        $recordType = isset($record['record_type']) ? (string) $record['record_type'] : 'provider:basia:unknown';
        $permalink = isset($record['permalink']) && is_string($record['permalink']) && $record['permalink'] !== ''
            ? $record['permalink']
            : null;
        $providerRecordId = isset($record['record_id']) && trim((string) $record['record_id']) !== ''
            ? trim((string) $record['record_id'])
            : hash('sha256', 'basia|' . ($permalink ?? '') . '|' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $fields = [
            'person' => [
                'name_raw' => $record['name'] ?? null,
                'given_name_raw' => $record['given_name'] ?? null,
                'surname_raw' => $record['surname'] ?? null,
                'age_raw' => $record['age'] ?? null,
                'father_raw' => $record['father'] ?? null,
                'mother_raw' => $record['mother'] ?? null,
                'parents_raw' => $record['parents_raw'] ?? null,
                'spouse_raw' => $record['spouse'] ?? null,
            ],
            'other_persons_raw' => $record['other_persons'] ?? [],
            'place_raw' => $record['place'] ?? null,
            'unit_type_raw' => $record['unit_type'] ?? null,
            'book_title_raw' => $record['book_title'] ?? null,
            'record_type_raw' => $record['record_type_label'] ?? null,
            'similarity_percent' => $record['similarity'] ?? null,
            'indexer_comment_raw' => $record['indexer_comment'] ?? null,
            'archive_raw' => $record['archive'] ?? null,
            'signature_raw' => $record['signature'] ?? null,
            'scan_label_raw' => $record['scan_label'] ?? null,
            'scan_url' => $record['scan_url'] ?? null,
            'permalink' => $permalink,
            'indexer_raw' => $record['indexer'] ?? null,
            'date_added_raw' => $record['date_added'] ?? null,
        ];

        $raw = [
            'record_id' => $record['record_id'] ?? null,
            'result_class_token' => $this->rawResultTypeToken($record['record_type_code'] ?? null),
            'record_type_label' => $record['record_type_label'] ?? null,
            'unit_type_label' => $record['unit_type'] ?? null,
            'name' => $record['name'] ?? null,
            'parents' => $record['parents_raw'] ?? null,
            'spouse' => $record['spouse'] ?? null,
            'other_persons' => $record['other_persons'] ?? [],
            'indexer_comment' => $record['indexer_comment'] ?? null,
            'scan_label' => $record['scan_label'] ?? null,
        ];

        $indexer = isset($record['indexer']) && is_string($record['indexer']) ? $record['indexer'] : null;

        return new ExternalIndexRecord(
            provider: 'basia',
            providerRecordId: $providerRecordId,
            recordType: $recordType,
            parish: null,
            year: isset($record['year']) ? (int) $record['year'] : null,
            fields: $fields,
            raw: $raw,
            provenance: [
                'source_url' => $permalink ?? $requestUrl,
                'request_url' => $requestUrl,
                'request_method' => 'POST',
                'query' => $search->configuration(),
                'query_fingerprint' => $queryFingerprint,
                'retrieved_at' => $retrievedAt,
                'raw_response_path' => $rawPath,
                'raw_response_sha256' => $rawSha256,
                'result_index' => $resultIndex,
                'parser_version' => BasiaHtmlParser::VERSION,
            ],
            representation: ValueRepresentation::indexerRendering('basia', $indexer),
        );
    }

    private function rawResultTypeToken(mixed $parserToken): ?string
    {
        return match ($parserToken) {
            'a' => 'usca',
            'b' => 'uscb',
            'c' => 'uscc',
            'd', 'z' => 'other',
            default => is_string($parserToken) && str_starts_with($parserToken, 'unknown:') ? 'other' : null,
        };
    }
}
