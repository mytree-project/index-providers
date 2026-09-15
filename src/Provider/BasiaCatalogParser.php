<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use DOMDocument;
use DOMElement;
use DOMNode;
use MyTree\IndexProviders\Domain\YearRange;
use RuntimeException;

final class BasiaCatalogParser
{
    public const VERSION = '1';

    /**
     * @return list<array<string,mixed>>
     */
    public function parse(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new RuntimeException('BASIA catalog returned malformed HTML that could not be parsed.');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMNode) {
            throw new BasiaIncompleteResponseException('BASIA catalog response has no HTML body.');
        }

        $lines = $this->lines($body);
        $localities = [];
        $current = null;
        $currentUnit = null;
        $inIndexers = false;

        foreach ($lines as $line) {
            if (preg_match('/^(.+?)\s*\(pow\.\s*(.+?)\)$/iu', $line, $match) === 1) {
                $this->flushUnit($current, $currentUnit);
                $this->flushLocality($localities, $current);
                $current = [
                    'locality_name' => trim($match[1]),
                    'county' => trim($match[2]),
                    'units' => [],
                    'total_records' => null,
                    'indexers' => [],
                    'total_seen' => false,
                    'indexers_seen' => false,
                ];
                $currentUnit = null;
                $inIndexers = false;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^Razem\s+wpisów:\s*([0-9 .]+)$/iu', $line, $match) === 1) {
                $this->flushUnit($current, $currentUnit);
                $current['total_records'] = (int) str_replace([' ', '.'], '', $match[1]);
                $current['total_seen'] = true;
                $inIndexers = false;
                continue;
            }

            if (preg_match('/^Indeksujący:\s*$/iu', $line) === 1) {
                $this->flushUnit($current, $currentUnit);
                $current['indexers_seen'] = true;
                $inIndexers = true;
                continue;
            }

            if ($inIndexers) {
                $indexer = trim($line, "- \t\n\r\0\x0B");
                if ($indexer !== '') {
                    $current['indexers'][] = $indexer;
                }
                continue;
            }

            if (preg_match('/^([^:]*):\s*([0-9,\-\s]*)$/u', $line, $match) === 1) {
                if ($currentUnit === null) {
                    $currentUnit = $this->unit(null);
                }
                $rawTypeLabel = trim($match[1]);
                $currentUnit['availability'][] = [
                    'record_type' => $this->recordType($rawTypeLabel),
                    'year_ranges' => $this->parseYearRanges(trim($match[2])),
                    'raw_type_label' => $rawTypeLabel,
                ];
                continue;
            }

            $this->flushUnit($current, $currentUnit);
            $currentUnit = $this->unit($line);
        }

        $this->flushUnit($current, $currentUnit);
        $this->flushLocality($localities, $current);

        if ($localities === []) {
            throw new BasiaIncompleteResponseException('BASIA catalog response contains no locality entries.');
        }

        $units = [];
        foreach ($localities as $locality) {
            if (!$locality['total_seen'] || !$locality['indexers_seen']) {
                throw new BasiaIncompleteResponseException(
                    'BASIA catalog locality is missing completion metadata: ' . $locality['locality_name'],
                );
            }

            if ($locality['units'] === []) {
                $locality['units'][] = $this->unit(null);
            }

            foreach ($locality['units'] as $unit) {
                $unit['locality_name'] = $locality['locality_name'];
                $unit['county'] = $locality['county'];
                $unit['locality_total_records'] = $locality['total_records'];
                $unit['indexers'] = array_values(array_unique($locality['indexers']));
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * @param array<string,mixed>|null $current
     * @param array<string,mixed>|null $currentUnit
     */
    private function flushUnit(?array &$current, ?array &$currentUnit): void
    {
        if ($current === null || $currentUnit === null) {
            return;
        }
        $current['units'][] = $currentUnit;
        $currentUnit = null;
    }

    /**
     * @param list<array<string,mixed>> $localities
     * @param array<string,mixed>|null $current
     */
    private function flushLocality(array &$localities, ?array &$current): void
    {
        if ($current === null) {
            return;
        }
        $localities[] = $current;
        $current = null;
    }

    /** @return array<string,mixed> */
    private function unit(?string $rawLabel): array
    {
        [$unitKind, $denomination] = $this->unitKind($rawLabel);

        return [
            'raw_unit_label' => $rawLabel,
            'unit_kind' => $unitKind,
            'denomination' => $denomination,
            'availability' => [],
        ];
    }

    /** @return array{0:string,1:?string} */
    private function unitKind(?string $rawLabel): array
    {
        $label = $rawLabel === null ? '' : trim($rawLabel);
        $normalized = strtolower($label);

        return match ($normalized) {
            'parafia katolicka' => ['parish', 'roman_catholic'],
            'parafia ewangelicka' => ['parish', 'evangelical'],
            'urząd stanu cywilnego' => ['civil_registry', null],
            'inne', 'inna', 'inny', 'pozostałe' => ['other', null],
            '' => ['provider:basia:unlabeled', null],
            default => ['provider:basia:' . $this->opaqueToken($label), null],
        };
    }

    private function recordType(string $rawLabel): string
    {
        return match (strtolower(trim($rawLabel))) {
            'urodzenia', 'chrzty' => 'birth',
            'małżeństwa' => 'marriage',
            'zgony' => 'death',
            'zapowiedzi' => 'banns',
            'inne' => 'other',
            '' => 'provider:basia:unlabeled',
            default => 'provider:basia:' . $this->opaqueToken($rawLabel),
        };
    }

    /** @return list<YearRange> */
    private function parseYearRanges(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $ranges = [];
        foreach (preg_split('/\s*,\s*/u', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{3,4})$/', $part, $match) === 1) {
                $year = (int) $match[1];
                $ranges[] = new YearRange($year, $year);
                continue;
            }
            if (preg_match('/^(\d{3,4})\s*-\s*(\d{3,4})$/', $part, $match) === 1) {
                $ranges[] = new YearRange((int) $match[1], (int) $match[2]);
                continue;
            }

            throw new RuntimeException('Malformed BASIA catalog year range: ' . $part);
        }

        return $ranges;
    }

    private function opaqueToken(string $value): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));

        return substr(hash('sha256', strtolower($normalized)), 0, 12);
    }

    /** @return list<string> */
    private function lines(DOMNode $root): array
    {
        $text = $this->textWithBreaks($root);
        $lines = [];
        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            $normalized = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($normalized !== '') {
                $lines[] = $normalized;
            }
        }

        return $lines;
    }

    private function textWithBreaks(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->nodeValue ?? '';
                continue;
            }
            if (!$child instanceof DOMElement) {
                $out .= $this->textWithBreaks($child);
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $out .= "\n";
                continue;
            }

            $block = in_array($tag, ['div', 'p', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article'], true);
            $spaced = in_array($tag, ['td', 'th'], true);
            if ($block) {
                $out .= "\n";
            } elseif ($spaced) {
                $out .= ' ';
            }

            $out .= $this->textWithBreaks($child);

            if ($block) {
                $out .= "\n";
            } elseif ($spaced) {
                $out .= ' ';
            }
        }

        return $out;
    }
}
