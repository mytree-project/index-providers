<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

use JsonException;
use RuntimeException;

final class AcquisitionRunLoader
{
    public const MANIFEST_SCHEMA = 'mytree.index-acquisition-manifest.v1';
    public const RECORD_SCHEMA = 'mytree.external-index-record.v1';

    public function load(string $directory): AcquisitionRun
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException("Acquisition run directory does not exist: $directory");
        }

        $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = $this->readJsonObject($manifestPath, 'manifest');
        $schema = $manifest['schema'] ?? null;
        if ($schema !== self::MANIFEST_SCHEMA) {
            $actual = is_scalar($schema) ? (string) $schema : get_debug_type($schema);
            throw new RuntimeException("Unsupported manifest schema in $manifestPath: $actual");
        }

        $recordsFile = $manifest['records_file'] ?? 'records.jsonl';
        if (!is_string($recordsFile) || trim($recordsFile) === '') {
            throw new RuntimeException("Invalid records_file in $manifestPath");
        }

        $recordsPath = $this->resolveRunFile($root, $recordsFile);
        if (!is_file($recordsPath) || !is_readable($recordsPath)) {
            throw new RuntimeException("Records file does not exist or is not readable: $recordsPath");
        }

        $records = [];
        $handle = fopen($recordsPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open records file: $recordsPath");
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Malformed JSONL in $recordsPath at line $lineNumber: {$e->getMessage()}", 0, $e);
                }

                if (!is_array($record)) {
                    throw new RuntimeException("Invalid record in $recordsPath at line $lineNumber: expected JSON object.");
                }

                $this->validateRecord($record, $recordsPath, $lineNumber);
                $records[] = $record;
            }
        } finally {
            fclose($handle);
        }

        return new AcquisitionRun($root, $manifest, $records);
    }

    /** @return array<string,mixed> */
    private function readJsonObject(string $path, string $label): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read $label file: $path");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Malformed JSON in $label file $path: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid $label file $path: expected JSON object.");
        }

        return $decoded;
    }

    private function resolveRunFile(string $root, string $relativePath): string
    {
        if ($this->isAbsolutePath($relativePath)) {
            throw new RuntimeException("records_file must be relative to the acquisition run: $relativePath");
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($candidate);
        if ($real === false) {
            return $candidate;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real !== $root && !str_starts_with($real, $prefix)) {
            throw new RuntimeException("records_file escapes the acquisition run directory: $relativePath");
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /** @param array<string,mixed> $record */
    private function validateRecord(array $record, string $path, int $lineNumber): void
    {
        $schema = $record['schema'] ?? null;
        if ($schema !== self::RECORD_SCHEMA) {
            $actual = is_scalar($schema) ? (string) $schema : get_debug_type($schema);
            throw new RuntimeException("Unsupported record schema in $path at line $lineNumber: $actual");
        }

        foreach (['provider', 'provider_record_id', 'record_type'] as $key) {
            if (!isset($record[$key]) || !is_string($record[$key]) || $record[$key] === '') {
                throw new RuntimeException("Invalid record in $path at line $lineNumber: '$key' must be a non-empty string.");
            }
        }

        if (isset($record['year']) && !is_int($record['year'])) {
            throw new RuntimeException("Invalid record in $path at line $lineNumber: 'year' must be an integer or null.");
        }

        foreach (['fields', 'raw', 'provenance'] as $key) {
            if (!isset($record[$key]) || !is_array($record[$key])) {
                throw new RuntimeException("Invalid record in $path at line $lineNumber: '$key' must be an object.");
            }
        }

        if (array_key_exists('representation', $record) && $record['representation'] !== null && !is_array($record['representation'])) {
            throw new RuntimeException("Invalid record in $path at line $lineNumber: 'representation' must be an object or null.");
        }
    }
}
