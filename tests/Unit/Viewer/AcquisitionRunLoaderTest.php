<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit\Viewer;

use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Viewer\AcquisitionRunLoader;
use RuntimeException;

final class AcquisitionRunLoaderTest extends TestCase
{
    public function testLoadsSupportedAcquisitionRun(): void
    {
        $run = $this->tmp . '/run';
        mkdir($run, 0775, true);
        file_put_contents($run . '/manifest.json', json_encode([
            'schema' => AcquisitionRunLoader::MANIFEST_SCHEMA,
            'provider' => 'basia',
            'records_file' => 'records.jsonl',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($run . '/records.jsonl', $this->record('1', 'birth', 1863) . PHP_EOL . $this->record('2', 'death', 1864) . PHP_EOL);

        $loaded = (new AcquisitionRunLoader())->load($run);

        self::assertSame(realpath($run), $loaded->directory);
        self::assertSame('basia', $loaded->manifest['provider']);
        self::assertCount(2, $loaded->records);
        self::assertSame('2', $loaded->records[1]['provider_record_id']);
    }

    public function testRejectsUnsupportedManifestSchema(): void
    {
        $run = $this->tmp . '/run';
        mkdir($run, 0775, true);
        file_put_contents($run . '/manifest.json', '{"schema":"future.v2","records_file":"records.jsonl"}');
        file_put_contents($run . '/records.jsonl', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported manifest schema');

        (new AcquisitionRunLoader())->load($run);
    }

    public function testReportsMalformedJsonlLine(): void
    {
        $run = $this->tmp . '/run';
        mkdir($run, 0775, true);
        file_put_contents($run . '/manifest.json', json_encode([
            'schema' => AcquisitionRunLoader::MANIFEST_SCHEMA,
            'records_file' => 'records.jsonl',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($run . '/records.jsonl', $this->record('1', 'birth', 1863) . PHP_EOL . '{broken' . PHP_EOL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at line 2');

        (new AcquisitionRunLoader())->load($run);
    }

    public function testRejectsRecordsFileOutsideRunDirectory(): void
    {
        $run = $this->tmp . '/run';
        mkdir($run, 0775, true);
        file_put_contents($this->tmp . '/outside.jsonl', $this->record('1', 'birth', 1863));
        file_put_contents($run . '/manifest.json', json_encode([
            'schema' => AcquisitionRunLoader::MANIFEST_SCHEMA,
            'records_file' => '../outside.jsonl',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes the acquisition run directory');

        (new AcquisitionRunLoader())->load($run);
    }

    private function record(string $id, string $type, int $year): string
    {
        return json_encode([
            'schema' => AcquisitionRunLoader::RECORD_SCHEMA,
            'provider' => 'basia',
            'provider_record_id' => $id,
            'record_type' => $type,
            'parish' => null,
            'year' => $year,
            'fields' => [],
            'raw' => [],
            'provenance' => [],
            'representation' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
