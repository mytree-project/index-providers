<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class BasiaHtmlParser
{
    public const VERSION = '1';

    private const BOLD_OPEN = "\x01";
    private const BOLD_CLOSE = "\x02";

    /**
     * @return array{records:list<array<string,mixed>>,search_time_seconds:?float}
     */
    public function parse(string $html, string $baseUrl = 'https://basia.famula.pl'): array
    {
        if (!preg_match('/Czas\s+wyszukiwania/iu', $html)) {
            throw new BasiaIncompleteResponseException(
                "BASIA response has no search-complete marker ('Czas wyszukiwania'); narrow the query and retry.",
            );
        }

        $searchTime = null;
        if (preg_match('/Czas\s+wyszukiwania:\s*([\d.,]+)\s*s/iu', $html, $match) === 1) {
            $candidate = str_replace(',', '.', $match[1]);
            if (is_numeric($candidate)) {
                $searchTime = (float) $candidate;
            }
        }

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
            throw new RuntimeException('BASIA returned malformed HTML that could not be parsed.');
        }

        $xpath = new DOMXPath($document);
        $boxes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result_box ')]");
        if ($boxes === false) {
            throw new RuntimeException('BASIA result-box query failed.');
        }

        $records = [];
        foreach ($boxes as $box) {
            if (!$box instanceof DOMElement) {
                continue;
            }
            $records[] = $this->parseBox($xpath, $box, $baseUrl);
        }

        return ['records' => $records, 'search_time_seconds' => $searchTime];
    }

    /** @return array<string,mixed> */
    private function parseBox(DOMXPath $xpath, DOMElement $box, string $baseUrl): array
    {
        $lbox = $this->firstElement(
            $xpath,
            ".//div[contains(concat(' ', normalize-space(@class), ' '), ' lbox ')]",
            $box,
        );
        $rbox = $this->firstElement(
            $xpath,
            ".//div[contains(concat(' ', normalize-space(@class), ' '), ' rbox ')]",
            $box,
        );
        if ($lbox === null || $rbox === null) {
            throw new RuntimeException('BASIA result box is missing the expected lbox/rbox structure.');
        }

        $fields = [];
        if (preg_match('/resboxid(\d+)/', $box->getAttribute('id'), $match) === 1) {
            $fields['record_id'] = $match[1];
        }
        $fields += $this->parseLbox($xpath, $lbox);
        $fields += $this->parseRbox($xpath, $rbox, $baseUrl);

        [$recordType, $rawCode] = $this->recordType($box, isset($fields['record_type_label']) ? (string) $fields['record_type_label'] : null);
        $fields['record_type'] = $recordType;
        $fields['record_type_code'] = $rawCode;

        return $fields;
    }

    /** @return array<string,mixed> */
    private function parseLbox(DOMXPath $xpath, DOMElement $lbox): array
    {
        $out = [];

        $progress = $this->firstElement(
            $xpath,
            ".//div[contains(concat(' ', normalize-space(@class), ' '), ' progress-container ')]",
            $lbox,
        );
        if ($progress !== null && preg_match('/(\d+)\s*%/', $progress->getAttribute('title'), $match) === 1) {
            $out['similarity'] = (int) $match[1];
        }

        $placeLink = $this->firstElement($xpath, ".//a[contains(@href, 'unit_info.php')]", $lbox);
        $skip = null;
        if ($placeLink !== null) {
            $out['place'] = $this->normalize($placeLink->textContent);
            $skip = $placeLink->parentNode instanceof DOMElement && strtolower($placeLink->parentNode->tagName) === 'span'
                ? $placeLink->parentNode
                : $placeLink;
        }

        $text = $this->flatten($lbox, $skip);
        $firstBold = strpos($text, self::BOLD_OPEN);
        $header = $firstBold === false ? $text : substr($text, 0, $firstBold);
        $body = $firstBold === false ? '' : substr($text, $firstBold);
        $this->parseHeader($header, $out);

        preg_match_all(
            '/' . preg_quote(self::BOLD_OPEN, '/') . '(.*?)' . preg_quote(self::BOLD_CLOSE, '/') . '/s',
            $body,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        $bolds = [];
        foreach ($matches[1] ?? [] as $index => $capture) {
            $whole = $matches[0][$index] ?? null;
            if (!is_array($whole) || !is_array($capture)) {
                continue;
            }
            $bolds[] = [
                'start' => (int) $whole[1],
                'end' => (int) $whole[1] + strlen((string) $whole[0]),
                'text' => $this->normalize((string) $capture[0]),
            ];
        }

        $regionEnd = strlen($body);
        foreach ($bolds as $bold) {
            if ($this->isSectionLabel($bold['text'])) {
                $regionEnd = min($regionEnd, $bold['start']);
            }
        }

        $persons = array_values(array_filter(
            $bolds,
            fn (array $bold): bool => $bold['start'] < $regionEnd && !$this->isSectionLabel($bold['text']),
        ));

        if ($persons !== []) {
            $principal = $persons[0];
            if ($principal['text'] !== '') {
                $out['name'] = $principal['text'];
                [$givenName, $surname] = $this->splitName($principal['text']);
                $out['given_name'] = $givenName;
                $out['surname'] = $surname;
            }

            $nextStart = isset($persons[1]) ? (int) $persons[1]['start'] : $regionEnd;
            $tail = substr($body, (int) $principal['end'], $nextStart - (int) $principal['end']);
            if (preg_match('/\((\d+\s*l(?:at|ata)?)\)/iu', $tail, $match) === 1) {
                $out['age'] = $this->normalize($match[1]);
            }
            if (preg_match('/rodzice:\s*(.*?)\s*(?:\n*małżonek|$)/isu', $tail, $match) === 1) {
                $parentsRaw = trim($this->normalize($match[1]), " ,\t\n\r\0\x0B");
                if ($parentsRaw !== '') {
                    $out['parents_raw'] = $parentsRaw;
                    [$father, $mother] = $this->splitParents($parentsRaw);
                    $out['father'] = $father;
                    $out['mother'] = $mother;
                }
            }
            if (isset($persons[1]) && $persons[1]['text'] !== '') {
                $out['spouse'] = $persons[1]['text'];
            }
        }

        $otherEnd = null;
        $comment = null;
        foreach ($bolds as $bold) {
            if (str_starts_with($bold['text'], 'Inne osoby')) {
                $otherEnd = (int) $bold['end'];
            }
            if (str_starts_with($bold['text'], 'Komentarz')) {
                $comment = [(int) $bold['start'], (int) $bold['end']];
            }
        }
        if ($otherEnd !== null) {
            $end = $comment !== null ? $comment[0] : strlen($body);
            $segment = ltrim(substr($body, $otherEnd, $end - $otherEnd), ": \n\r\t");
            $others = [];
            foreach (preg_split('/\R+/', $segment) ?: [] as $line) {
                $value = $this->normalize($line);
                if ($value !== '') {
                    $others[] = $value;
                }
            }
            $out['other_persons'] = $others;
        }
        if ($comment !== null) {
            $value = $this->normalize(ltrim(substr($body, $comment[1]), ": \n\r\t"));
            if ($value !== '') {
                $out['indexer_comment'] = $value;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function parseRbox(DOMXPath $xpath, DOMElement $rbox, string $baseUrl): array
    {
        $out = [];

        $archive = $this->firstElement($xpath, ".//a[contains(@href, 'showbox.php')]", $rbox);
        if ($archive !== null) {
            $out['archive'] = $this->normalize($archive->textContent);
        }

        $anchors = $xpath->query('.//a[@href]', $rbox);
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $href = trim($anchor->getAttribute('href'));
                $label = $this->normalize($anchor->textContent);
                if (!isset($out['scan_url']) && preg_match('/\bskan\b/iu', $label) === 1) {
                    $out['scan_url'] = $this->absoluteUrl($href, $baseUrl);
                    $out['scan_label'] = $label;
                    if (preg_match('/^(.*?),\s*skan\b/iu', $label, $match) === 1) {
                        $out['signature'] = trim($match[1]);
                    }
                }
                if (!isset($out['indexer']) && preg_match('~/profile/\d+~', $href) === 1) {
                    $out['indexer'] = $label;
                }
                if (!isset($out['permalink']) && str_starts_with($href, '/record/')) {
                    $out['permalink'] = $this->absoluteUrl($href, $baseUrl);
                }
            }
        }

        if (preg_match('/dodano:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/iu', $rbox->textContent, $match) === 1) {
            $out['date_added'] = $match[1];
        }

        return $out;
    }

    /** @param array<string,mixed> $out */
    private function parseHeader(string $header, array &$out): void
    {
        $rest = $this->normalize($header);
        if (preg_match('/^\(([^)]*)\)\s*-?\s*/u', $rest, $match) === 1) {
            $out['unit_type'] = trim($match[1]);
            $rest = trim(substr($rest, strlen($match[0])));
        }

        if (preg_match('/^(.*?),\s*rok\s*(\d{3,4})\b\s*(?:,\s*(.*))?$/isu', $rest, $match) === 1) {
            $out['record_type_label'] = trim($match[1]);
            $out['year'] = (int) $match[2];
            $book = isset($match[3]) ? trim($match[3]) : '';
            if ($book !== '') {
                $out['book_title'] = $book;
            }
            return;
        }

        if (preg_match('/^(.*?)\s+(\d{3,4})\b\s*(?:,\s*(.*))?$/isu', $rest, $match) === 1) {
            $out['record_type_label'] = trim($match[1]);
            $out['year'] = (int) $match[2];
            $book = isset($match[3]) ? trim($match[3]) : '';
            if ($book !== '') {
                $out['book_title'] = $book;
            }
            return;
        }

        if ($rest !== '') {
            $out['record_type_label'] = trim($rest, " ,\t\n\r\0\x0B");
        }
    }

    /** @return array{0:string,1:string} */
    private function recordType(DOMElement $box, ?string $label): array
    {
        $classes = preg_split('/\s+/', trim($box->getAttribute('class'))) ?: [];
        if (in_array('usca', $classes, true)) {
            return ['birth', 'a'];
        }
        if (in_array('uscb', $classes, true)) {
            return ['marriage', 'b'];
        }
        if (in_array('uscc', $classes, true)) {
            return ['death', 'c'];
        }
        if ($label !== null && preg_match('/zapowied/iu', $label) === 1) {
            return ['banns', 'd'];
        }
        if ($label !== null && preg_match('/\binn(?:e|y|a)\b/iu', $label) === 1) {
            return ['other', 'z'];
        }

        $tokenSource = $label !== null && trim($label) !== '' ? trim($label) : implode('-', $classes);
        $token = substr(hash('sha256', $tokenSource), 0, 12);

        return ['provider:basia:' . $token, 'unknown:' . $token];
    }

    private function flatten(DOMNode $node, ?DOMNode $skip = null): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($skip !== null && $child->isSameNode($skip)) {
                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= $child->nodeValue ?? '';
                continue;
            }
            if (!$child instanceof DOMElement) {
                $out .= $this->flatten($child, $skip);
                continue;
            }
            if ($this->hasClass($child, 'progress-container')) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $out .= "\n";
            } elseif ($tag === 'b') {
                $out .= self::BOLD_OPEN . $this->normalize($child->textContent) . self::BOLD_CLOSE;
            } else {
                $out .= $this->flatten($child, $skip);
            }
        }

        return $out;
    }

    private function firstElement(DOMXPath $xpath, string $query, DOMNode $context): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false) {
            return null;
        }
        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    private function normalize(string $text): string
    {
        $text = str_replace([self::BOLD_OPEN, self::BOLD_CLOSE], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function isSectionLabel(string $text): bool
    {
        return str_starts_with($text, 'Inne osoby') || str_starts_with($text, 'Komentarz');
    }

    /** @return array{0:?string,1:?string} */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), 2) ?: [];

        return [$parts[0] ?? null, isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null];
    }

    /** @return array{0:?string,1:?string} */
    private function splitParents(string $raw): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $part): bool => $part !== ''));

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    private function absoluteUrl(string $href, string $baseUrl): string
    {
        if ($href === '' || preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
    }
}
