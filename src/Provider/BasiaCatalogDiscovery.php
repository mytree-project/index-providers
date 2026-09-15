<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use MyTree\IndexProviders\Contracts\ProgressReporterInterface;
use MyTree\IndexProviders\Domain\IndexCatalogAvailability;
use MyTree\IndexProviders\Domain\IndexCatalogLocality;
use MyTree\IndexProviders\Domain\IndexCatalogUnit;
use MyTree\IndexProviders\Domain\YearRange;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\RateLimiter;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;

final class BasiaCatalogDiscovery
{
    private const CACHE_KEY = 'catalog_pl';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RawResponseStore $rawStore,
        private readonly RateLimiter $rateLimiter,
        private readonly ProgressReporterInterface $progress,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $baseUrl,
        private readonly BasiaCatalogParser $parser = new BasiaCatalogParser(),
    ) {
    }

    /** @return list<IndexCatalogUnit> */
    public function listCatalogUnits(bool $refresh = false): array
    {
        $requestUrl = rtrim($this->baseUrl, '/') . '/content-all.php?lang=pl';
        $body = !$refresh ? $this->rawStore->get('basia', self::CACHE_KEY, 'html') : null;
        $cacheMeta = !$refresh ? $this->rawStore->metadata('basia', self::CACHE_KEY, 'html') : null;

        if ($body !== null) {
            try {
                $parsed = $this->parser->parse($body);
                $retrievedAt = is_array($cacheMeta) && isset($cacheMeta['retrieved_at'])
                    ? (string) $cacheMeta['retrieved_at']
                    : gmdate(DATE_ATOM);
                $rawPath = $this->rawStore->path('basia', self::CACHE_KEY, 'html');
                $rawSha256 = hash('sha256', $body);
                $this->progress->info('BASIA catalog: using cached complete response.');

                return $this->mapUnits($parsed, $requestUrl, $rawPath, $rawSha256, $retrievedAt);
            } catch (RuntimeException) {
                $body = null;
            }
        }

        $this->progress->info('BASIA catalog: fetching indexed-content catalogue.');
        $this->rateLimiter->beforeRequest();
        $request = $this->requestFactory
            ->createRequest('GET', $requestUrl)
            ->withHeader('Accept', 'text/html,application/xhtml+xml');
        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("BASIA HTTP {$status}: $requestUrl");
        }

        $body = (string) $response->getBody();
        $retrievedAt = gmdate(DATE_ATOM);
        $rawSha256 = hash('sha256', $body);

        // Parse before writing the reusable cache. A partial or malformed
        // catalogue must never become a successful discovery cache entry.
        $parsed = $this->parser->parse($body);
        $rawPath = $this->rawStore->put('basia', self::CACHE_KEY, 'html', $body, [
            'provider' => 'basia',
            'purpose' => 'index_catalog',
            'requested_url' => $requestUrl,
            'request_method' => 'GET',
            'http_status' => $status,
            'retrieved_at' => $retrievedAt,
            'parser_version' => BasiaCatalogParser::VERSION,
            'catalog_unit_count' => count($parsed),
            'complete' => true,
        ]);

        return $this->mapUnits($parsed, $requestUrl, $rawPath, $rawSha256, $retrievedAt);
    }

    /**
     * @param list<array<string,mixed>> $parsed
     * @return list<IndexCatalogUnit>
     */
    private function mapUnits(
        array $parsed,
        string $requestUrl,
        string $rawPath,
        string $rawSha256,
        string $retrievedAt,
    ): array {
        $units = [];
        foreach ($parsed as $index => $unit) {
            $availability = [];
            foreach ($unit['availability'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ranges = array_values(array_filter(
                    $item['year_ranges'] ?? [],
                    static fn (mixed $range): bool => $range instanceof YearRange,
                ));
                $availability[] = new IndexCatalogAvailability(
                    recordType: (string) ($item['record_type'] ?? 'provider:basia:unlabeled'),
                    yearRanges: $ranges,
                    rawTypeLabel: isset($item['raw_type_label']) ? (string) $item['raw_type_label'] : null,
                );
            }

            $localityName = trim((string) ($unit['locality_name'] ?? ''));
            if ($localityName === '') {
                throw new RuntimeException('BASIA catalog unit is missing locality name.');
            }
            $county = isset($unit['county']) && trim((string) $unit['county']) !== ''
                ? trim((string) $unit['county'])
                : null;
            $rawUnitLabel = isset($unit['raw_unit_label']) && trim((string) $unit['raw_unit_label']) !== ''
                ? trim((string) $unit['raw_unit_label'])
                : null;

            $units[] = new IndexCatalogUnit(
                provider: 'basia',
                catalogUnitKey: $this->catalogUnitKey($localityName, $county, $rawUnitLabel),
                unitKind: (string) ($unit['unit_kind'] ?? 'provider:basia:unlabeled'),
                providerUnitId: null,
                locality: new IndexCatalogLocality(
                    name: $localityName,
                    county: $county,
                ),
                unitName: $rawUnitLabel,
                denomination: isset($unit['denomination']) && $unit['denomination'] !== null
                    ? (string) $unit['denomination']
                    : null,
                availability: $availability,
                raw: [
                    'locality_label' => $localityName,
                    'county_label' => $county,
                    'unit_label' => $rawUnitLabel,
                    'availability' => array_map(
                        static function (IndexCatalogAvailability $item): array {
                            return [
                                'record_type_label' => $item->rawTypeLabel,
                                'year_ranges' => array_map(
                                    static fn (YearRange $range): array => $range->jsonSerialize(),
                                    $item->yearRanges,
                                ),
                            ];
                        },
                        $availability,
                    ),
                ],
                provenance: [
                    'source_url' => $requestUrl,
                    'request_url' => $requestUrl,
                    'request_method' => 'GET',
                    'retrieved_at' => $retrievedAt,
                    'raw_response_path' => $rawPath,
                    'raw_response_sha256' => $rawSha256,
                    'catalog_index' => (int) $index,
                    'parser_version' => BasiaCatalogParser::VERSION,
                ],
                metadata: [
                    'locality_total_records' => $unit['locality_total_records'] ?? null,
                    'indexers' => is_array($unit['indexers'] ?? null) ? array_values($unit['indexers']) : [],
                ],
            );
        }

        return $units;
    }

    private function catalogUnitKey(string $localityName, ?string $county, ?string $rawUnitLabel): string
    {
        $identity = json_encode(
            [
                'locality' => $localityName,
                'county' => $county,
                'unit_label' => $rawUnitLabel,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return 'basia:' . substr(hash('sha256', $identity), 0, 24);
    }
}
