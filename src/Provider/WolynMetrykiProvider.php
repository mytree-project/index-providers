<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use MyTree\IndexProviders\Contracts\CheckpointStoreInterface;
use MyTree\IndexProviders\Contracts\ProgressReporterInterface;
use MyTree\IndexProviders\Contracts\RecordWriterInterface;
use MyTree\IndexProviders\Domain\AcquisitionStats;
use MyTree\IndexProviders\Domain\AvailableParish;
use MyTree\IndexProviders\Domain\ExternalIndexRecord;
use MyTree\IndexProviders\Domain\ValueRepresentation;
use MyTree\IndexProviders\Storage\RawResponseStore;
use MyTree\IndexProviders\Support\NullProgressReporter;
use MyTree\IndexProviders\Support\RateLimiter;
use MyTree\IndexProviders\Support\SequentialHtmlTableParser;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class WolynMetrykiProvider
{
    private readonly RequestFactoryInterface $requestFactory;

    private const ENDPOINT = 'https://wolyn-metryki.pl/Wolyn/index.php';
    private const CONTENT_ENDPOINTS = [
        'https://www.wolyn-metryki.pl/nowa/index.php/zawartosc',
        'https://wolyn-metryki.pl/joomla/zawartosc',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CheckpointStoreInterface $checkpoints,
        private readonly RawResponseStore $rawStore,
        private readonly RateLimiter $rateLimiter = new RateLimiter(2000),
        private readonly SequentialHtmlTableParser $tableParser = new SequentialHtmlTableParser(),
        private readonly WolynParishListParser $parishListParser = new WolynParishListParser(),
        private readonly ProgressReporterInterface $progress = new NullProgressReporter(),
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        $this->requestFactory = $requestFactory ?? new HttpFactory();
    }

    /** @return list<AvailableParish> */
    public function listParishes(bool $force = false): array
    {
        $lastError = null;
        foreach (self::CONTENT_ENDPOINTS as $index => $url) {
            $cacheKey = 'parish-discovery_' . ($index === 0 ? 'current' : 'legacy');
            $body = !$force ? $this->rawStore->get('wolyn-metryki', $cacheKey, 'html') : null;
            if ($body === null) {
                try {
                    $this->rateLimiter->beforeRequest();
                    $response = $this->sendGet($url, [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    ]);
                    $status = $response->getStatusCode();
                    if ($status < 200 || $status >= 300) {
                        $lastError = "HTTP {$status} for $url";
                        continue;
                    }
                    $body = (string) $response->getBody();
                    $this->rawStore->put('wolyn-metryki', $cacheKey, 'html', $body, [
                        'provider' => 'wolyn-metryki',
                        'purpose' => 'parish_discovery',
                        'requested_url' => $url,
                        'http_status' => $status,
                        'retrieved_at' => gmdate(DATE_ATOM),
                    ]);
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    continue;
                }
            }

            $items = $this->parishListParser->parse($body, $url);
            if ($items !== []) {
                $this->progress->info('Metryki-Wołyń: discovered ' . count($items) . ' parishes.');
                return $items;
            }
            $lastError = 'No parish table recognized at ' . $url;
        }

        throw new RuntimeException('Could not discover Metryki-Wołyń parishes. ' . ($lastError ?? 'Unknown error.'));
    }

    public function acquire(
        string $parish,
        int $fromYear,
        int $toYear,
        RecordWriterInterface $writer,
        bool $force = false,
    ): AcquisitionStats {
        if ($fromYear > $toYear) {
            throw new RuntimeException('fromYear must be <= toYear.');
        }

        $stats = new AcquisitionStats();
        for ($year = $fromYear; $year <= $toYear; $year++) {
            $checkpointKey = 'wolyn:' . $this->slug($parish) . ':year:' . $year;
            if (!$force && $this->checkpoints->get($checkpointKey) === true) {
                $stats->skippedUnits++;
                continue;
            }

            $url = self::ENDPOINT . '?' . http_build_query([
                'imie_szuk' => '',
                'nazw_szuk' => '',
                'miej_szuk' => '',
                'para_szuk' => $parish,
                'rok_start_szuk' => (string) $year,
                'rok_koniec_szuk' => (string) $year,
            ], '', '&', PHP_QUERY_RFC3986);

            $cacheKey = $this->slug($parish) . '_' . $year;
            $cachedBody = !$force ? $this->rawStore->get('wolyn-metryki', $cacheKey, 'html') : null;
            $cacheMeta = !$force ? $this->rawStore->metadata('wolyn-metryki', $cacheKey, 'html') : null;
            if ($cachedBody !== null) {
                $this->progress->info("Metryki-Wołyń $parish/$year: using cached response.");
                $body = $cachedBody;
                $retrievedAt = is_array($cacheMeta) && isset($cacheMeta['retrieved_at']) ? (string) $cacheMeta['retrieved_at'] : gmdate(DATE_ATOM);
                $rawPath = $this->rawStore->path('wolyn-metryki', $cacheKey, 'html');
                $rawSha256 = hash('sha256', $body);
            } else {
                $this->progress->info("Metryki-Wołyń $parish: year $year.");
                $this->rateLimiter->beforeRequest();
                $response = $this->sendGet($url, [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => self::ENDPOINT,
                ]);
                $stats->requests++;
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new RuntimeException("Metryki-Wołyń HTTP {$status}: $url");
                }
                $body = (string) $response->getBody();
                $retrievedAt = gmdate(DATE_ATOM);
                $rawSha256 = hash('sha256', $body);
                $rawPath = $this->rawStore->put('wolyn-metryki', $cacheKey, 'html', $body, [
                    'provider' => 'wolyn-metryki',
                    'requested_url' => $url,
                    'http_status' => $status,
                    'retrieved_at' => $retrievedAt,
                    'parish' => $parish,
                    'year' => $year,
                ]);
                $stats->cacheWrites++;
            }

            $tables = $this->tableParser->parse($body);
            $yearRecords = 0;
            $fingerprintOccurrences = [];
            foreach ($tables as $table) {
                $recordType = $this->recordType($table['title']);
                if (str_starts_with($recordType, 'provider:wolyn-metryki:')) {
                    $this->progress->warning(
                        "Unknown Metryki-Wołyń section '{$table['title']}' preserved as opaque record type $recordType.",
                    );
                }
                foreach ($table['rows'] as $rowIndex => $row) {
                    if ($row['cells'] === []) {
                        continue;
                    }
                    $baseFingerprint = hash('sha256', json_encode(['record_type' => $recordType, 'cells' => $row['cells'], 'hrefs' => $row['hrefs']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $occurrenceKey = $recordType . ':' . $baseFingerprint;
                    $duplicateOrdinal = ($fingerprintOccurrences[$occurrenceKey] ?? 0) + 1;
                    $fingerprintOccurrences[$occurrenceKey] = $duplicateOrdinal;
                    $record = $this->mapRecord(
                        recordType: $recordType,
                        title: $table['title'],
                        headers: $table['headers'],
                        cells: $row['cells'],
                        cellHtml: $row['html'],
                        hrefs: $row['hrefs'],
                        baseFingerprint: $baseFingerprint,
                        duplicateOrdinal: $duplicateOrdinal,
                        requestedParish: $parish,
                        requestedYear: $year,
                        rowIndex: (int) $rowIndex,
                        requestUrl: $url,
                        rawPath: $rawPath,
                        rawSha256: $rawSha256,
                        retrievedAt: $retrievedAt,
                    );
                    $writer->write($record);
                    $stats->record($recordType);
                    $yearRecords++;
                }
            }

            $this->progress->info("Metryki-Wołyń $parish/$year: $yearRecords records parsed.");
            $this->checkpoints->set($checkpointKey, true);
        }

        return $stats;
    }

    /**
     * @param list<string> $headers
     * @param list<string> $cells
     * @param list<string> $cellHtml
     * @param list<list<string>> $hrefs
     */
    private function mapRecord(
        string $recordType,
        string $title,
        array $headers,
        array $cells,
        array $cellHtml,
        array $hrefs,
        string $baseFingerprint,
        int $duplicateOrdinal,
        string $requestedParish,
        int $requestedYear,
        int $rowIndex,
        string $requestUrl,
        string $rawPath,
        string $rawSha256,
        string $retrievedAt,
    ): ExternalIndexRecord {
        $knownType = !str_starts_with($recordType, 'provider:wolyn-metryki:');
        $fields = match ($recordType) {
            'death' => $this->mapDeath($cells, $hrefs),
            'birth' => $this->mapBirth($cells, $hrefs),
            'marriage' => $this->mapMarriage($cells, $hrefs),
            'parish_census' => $this->mapParishCensus($cells, $hrefs),
            default => [
                'provider_table' => [
                    'section_title_raw' => $title,
                    'headers_raw' => $headers,
                    'cells_raw' => $cells,
                    'hrefs' => $hrefs,
                ],
            ],
        };

        $yearRaw = $knownType
            ? ($recordType === 'parish_census' ? ($cells[8] ?? '') : ($cells[2] ?? ''))
            : '';
        $parishRaw = $knownType
            ? ($recordType === 'parish_census' ? ($cells[6] ?? '') : ($cells[3] ?? ''))
            : '';
        $year = preg_match('~^\d{4}$~', $yearRaw) ? (int) $yearRaw : null;
        if ($year !== null && $year !== $requestedYear) {
            $this->progress->warning("Metryki-Wołyń returned year $year for request $requestedYear.");
        }
        if ($parishRaw !== '' && trim($parishRaw) !== trim($requestedParish)) {
            $this->progress->warning("Metryki-Wołyń returned parish '$parishRaw' for request '$requestedParish'.");
        }

        $providerRecordId = hash('sha256', 'wolyn-metryki|' . $baseFingerprint . '|occurrence:' . $duplicateOrdinal);

        return new ExternalIndexRecord(
            provider: 'wolyn-metryki',
            providerRecordId: $providerRecordId,
            recordType: $recordType,
            parish: $parishRaw !== '' ? $parishRaw : $requestedParish,
            year: $year,
            fields: $fields,
            raw: [
                'section_title' => $title,
                'headers' => $headers,
                'cells' => $cells,
                'cell_html' => $cellHtml,
                'hrefs' => $hrefs,
            ],
            provenance: [
                'provider' => 'wolyn-metryki',
                'requested_parish' => $requestedParish,
                'requested_year' => $requestedYear,
                'row_index' => $rowIndex,
                'request_url' => $requestUrl,
                'retrieved_at' => $retrievedAt,
                'raw_response_path' => $rawPath,
                'raw_response_sha256' => $rawSha256,
                'content_fingerprint' => $baseFingerprint,
                'duplicate_ordinal' => $duplicateOrdinal,
                'id_strategy' => 'content_fingerprint_plus_duplicate_ordinal',
            ],
            representation: ValueRepresentation::indexerRendering(
                provider: 'wolyn-metryki',
                indexedBy: $this->indexerFromFields($fields),
            ),
        );
    }

    /** @param array<string,mixed> $fields */
    private function indexerFromFields(array $fields): ?string
    {
        $candidate = $fields['indexed_by_raw'] ?? null;
        if (!is_string($candidate)) {
            $locator = $fields['source_locator'] ?? null;
            $candidate = is_array($locator) ? ($locator['indexed_by_raw'] ?? null) : null;
        }

        if (!is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        return $candidate !== '' ? $candidate : null;
    }

    /** @param list<string> $c @param list<list<string>> $h @return array<string,mixed> */
    private function mapDeath(array $c, array $h): array
    {
        $this->expectColumns($c, 16, 'death');
        return [
            'date' => $this->dateFields($c[0], $c[1], $c[2]),
            'parish_raw' => $c[3],
            'deceased' => [
                'given_names_raw' => $c[4],
                'surname_raw' => $c[5],
                'age_raw' => $c[6],
            ],
            'place_raw' => $c[7],
            'family_and_notes_raw' => $c[8],
            'source_locator' => $this->locator($c, $h, 9, 10, 11, 12, 13, 14, 15),
        ];
    }

    /** @param list<string> $c @param list<list<string>> $h @return array<string,mixed> */
    private function mapBirth(array $c, array $h): array
    {
        $this->expectColumns($c, 18, 'birth');
        return [
            'date' => $this->dateFields($c[0], $c[1], $c[2]),
            'parish_raw' => $c[3],
            'child' => [
                'given_names_raw' => $c[4],
                'surname_raw' => $c[5],
            ],
            'place_raw' => $c[6],
            'father_given_names_raw' => $c[7],
            'mother_given_names_raw' => $c[8],
            'mother_surname_raw' => $c[9],
            'godparents_and_notes_raw' => $c[10],
            'source_locator' => $this->locator($c, $h, 11, 12, 13, 14, 15, 16, 17),
        ];
    }

    /** @param list<string> $c @param list<list<string>> $h @return array<string,mixed> */
    private function mapMarriage(array $c, array $h): array
    {
        $this->expectColumns($c, 26, 'marriage');
        return [
            'date' => $this->dateFields($c[0], $c[1], $c[2]),
            'parish_raw' => $c[3],
            'groom' => [
                'given_names_raw' => $c[4],
                'surname_raw' => $c[5],
                'origin_raw' => $c[6],
                'age_raw' => $c[7],
                'father_given_names_raw' => $c[8],
                'mother_given_names_raw' => $c[9],
                'mother_surname_raw' => $c[10],
            ],
            'bride' => [
                'given_names_raw' => $c[11],
                'surname_raw' => $c[12],
                'origin_raw' => $c[13],
                'age_raw' => $c[14],
                'father_given_names_raw' => $c[15],
                'mother_given_names_raw' => $c[16],
                'mother_surname_raw' => $c[17],
            ],
            'witnesses_and_notes_raw' => $c[18],
            'source_locator' => $this->locator($c, $h, 19, 20, 21, 22, 23, 24, 25),
        ];
    }

    /** @param list<string> $c @param list<list<string>> $h @return array<string,mixed> */
    private function mapParishCensus(array $c, array $h): array
    {
        $this->expectColumns($c, 15, 'parish_census');
        return [
            'household_number_raw' => $c[0],
            'male_number_raw' => $c[1],
            'female_number_raw' => $c[2],
            'personalia_raw' => $c[3],
            'male_age_raw' => $c[4],
            'female_age_raw' => $c[5],
            'parish_raw' => $c[6],
            'place_raw' => $c[7],
            'year_raw' => $c[8],
            'archive_raw' => $c[9],
            'indexed_by_raw' => $c[10],
            'signature_raw' => $c[11],
            'page_raw' => $c[12],
            'scan_number_raw' => $c[13],
            'notes_raw' => $c[14],
            'links' => array_values(array_unique(array_merge(...$h))),
        ];
    }

    /** @return array{day_raw:string,month_raw:string,year_raw:string,iso:?string} */
    private function dateFields(string $day, string $month, string $year): array
    {
        $iso = null;
        if (ctype_digit($year) && ctype_digit($month) && ctype_digit($day)) {
            $y = (int) $year;
            $m = (int) $month;
            $d = (int) $day;
            if (checkdate($m, $d, $y)) {
                $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        }
        return ['day_raw' => $day, 'month_raw' => $month, 'year_raw' => $year, 'iso' => $iso];
    }

    /**
     * @param list<string> $c
     * @param list<list<string>> $h
     * @return array<string,mixed>
     */
    private function locator(array $c, array $h, int $sig, int $page, int $position, int $archive, int $scan, int $indexer, int $scanUrl): array
    {
        $links = $h[$scanUrl] ?? [];
        return [
            'signature_raw' => $c[$sig] ?? '',
            'page_raw' => $c[$page] ?? '',
            'position_raw' => $c[$position] ?? '',
            'archive_raw' => $c[$archive] ?? '',
            'scan_number_raw' => $c[$scan] ?? '',
            'indexed_by_raw' => $c[$indexer] ?? '',
            'scan_label_raw' => $c[$scanUrl] ?? '',
            'scan_url' => $links[0] ?? null,
        ];
    }

    /** @param list<string> $cells */
    private function expectColumns(array $cells, int $expected, string $type): void
    {
        if (count($cells) !== $expected) {
            throw new RuntimeException("Metryki-Wołyń $type row: expected $expected columns, got " . count($cells) . '.');
        }
    }

    /** @param array<string,string> $headers */
    private function sendGet(string $url, array $headers = []): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->http->sendRequest($request);
    }

    private function recordType(string $title): string
    {
        $canonical = $this->canonicalType($title);
        if ($canonical !== null) {
            return $canonical;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $title));
        $token = substr(hash('sha256', $normalized), 0, 12);

        return 'provider:wolyn-metryki:' . $token;
    }

    private function canonicalType(string $title): ?string
    {
        $normalized = trim($title);
        return match ($normalized) {
            'Zgony', 'zgony' => 'death',
            'Urodzenia', 'urodzenia' => 'birth',
            'Śluby', 'śluby', 'Sluby', 'sluby' => 'marriage',
            'Spisy parafian', 'spisy parafian' => 'parish_census',
            default => null,
        };
    }

    private function slug(string $value): string
    {
        $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
        $ascii = $ascii === false ? $value : $ascii;
        return trim(strtolower(preg_replace('~[^A-Za-z0-9]+~', '-', $ascii) ?? $ascii), '-');
    }
}
