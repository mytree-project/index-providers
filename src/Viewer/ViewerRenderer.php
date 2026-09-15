<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

final class ViewerRenderer
{
    public function render(AcquisitionRun $run, ViewerState $state, int $width, int $height, ?string $prompt = null, ?string $message = null): string
    {
        $width = max(60, $width);
        $height = max(12, $height);

        if ($state->view() === 'details') {
            return $this->renderDetails($run, $state, $width, $height, $message);
        }
        if ($state->view() === 'help') {
            return $this->renderHelp($width, $height);
        }

        return $this->renderList($run, $state, $width, $height, $prompt, $message);
    }

    private function renderList(AcquisitionRun $run, ViewerState $state, int $width, int $height, ?string $prompt, ?string $message): string
    {
        $manifest = $run->manifest;
        $stats = is_array($manifest['stats'] ?? null) ? $manifest['stats'] : [];
        $writer = is_array($manifest['writer'] ?? null) ? $manifest['writer'] : [];
        $configuration = is_array($manifest['configuration'] ?? null) ? $manifest['configuration'] : [];
        $visible = $state->visibleRecords();

        $lines = [];
        $lines[] = $this->fit(sprintf(
            'MyTree Index Results  provider=%s  records=%s  requests=%s  written=%s  duplicates=%s',
            (string) ($manifest['provider'] ?? 'unknown'),
            (string) ($stats['records'] ?? count($run->records)),
            (string) ($stats['requests'] ?? '—'),
            (string) ($writer['written_this_run'] ?? '—'),
            (string) ($writer['duplicates_skipped_this_run'] ?? '—'),
        ), $width);
        $lines[] = $this->fit('Run: ' . (string) ($manifest['started_at'] ?? '—') . ' → ' . (string) ($manifest['finished_at'] ?? '—'), $width);
        $lines[] = $this->fit('Query: ' . $this->inlineMap($configuration), $width);
        $status = 'Showing ' . count($visible) . '/' . count($run->records);
        if ($state->search() !== '') {
            $status .= '  search=' . $state->search();
        }
        if ($state->filterExpression() !== '') {
            $status .= '  filters=' . $state->filterExpression();
        }
        $lines[] = $this->fit($status, $width);
        $lines[] = str_repeat('─', $width);

        [$columns, $widths] = $this->columns($width);
        $header = [];
        foreach ($columns as $i => $column) {
            $header[] = $this->pad($column, $widths[$i]);
        }
        $lines[] = $this->fit(implode(' ', $header), $width);

        $availableRows = max(1, $height - 8);
        $selected = $state->selectedIndex();
        $start = max(0, $selected - intdiv($availableRows, 2));
        if ($start + $availableRows > count($visible)) {
            $start = max(0, count($visible) - $availableRows);
        }

        for ($i = $start; $i < min(count($visible), $start + $availableRows); $i++) {
            $record = $visible[$i];
            $cells = $this->recordCells($record, $columns);
            $parts = [];
            foreach ($cells as $j => $cell) {
                $parts[] = $this->pad($cell, $widths[$j]);
            }
            $prefix = $i === $selected ? '› ' : '  ';
            $lines[] = $this->fit($prefix . implode(' ', $parts), $width);
        }

        while (count($lines) < $height - 2) {
            $lines[] = '';
        }

        if ($message !== null && $message !== '') {
            $lines[] = $this->fit($message, $width);
        } else {
            $lines[] = $this->fit('↑/↓ j/k move  Enter details  / search  f filters  c clear  ? help  q quit', $width);
        }
        $lines[] = $prompt !== null ? $this->fit($prompt, $width) : '';

        return implode(PHP_EOL, array_slice($lines, 0, $height));
    }

    private function renderDetails(AcquisitionRun $run, ViewerState $state, int $width, int $height, ?string $message): string
    {
        $record = $state->current();
        if ($record === null) {
            $state->showList();
            return $this->render($run, $state, $width, $height, null, $message);
        }

        $lines = ['MyTree Index Results — Record details', str_repeat('─', $width)];
        $data = $record->record;
        $identity = [];
        foreach (['schema', 'provider', 'provider_record_id', 'record_type', 'parish', 'year'] as $key) {
            $identity[$key] = $data[$key] ?? null;
        }
        $lines = array_merge($lines, $this->section('IDENTITY', $identity, $width));

        $locators = $this->extractLocators($data);
        if ($locators !== []) {
            $lines = array_merge($lines, $this->section('LOCATORS', $locators, $width));
        }
        foreach (['fields' => 'FIELDS', 'raw' => 'RAW PROVIDER VALUES', 'provenance' => 'PROVENANCE', 'representation' => 'REPRESENTATION'] as $key => $title) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $lines = array_merge($lines, $this->section($title, $data[$key], $width));
            }
        }

        $bodyHeight = $height - 1;
        $total = count($lines);
        $maxOffset = max(0, $total - $bodyHeight);
        $offset = min($state->detailOffset(), $maxOffset);
        $visibleLines = array_slice($lines, $offset, $bodyHeight);
        while (count($visibleLines) < $bodyHeight) {
            $visibleLines[] = '';
        }
        $position = $maxOffset > 0 ? sprintf('  lines %d-%d/%d', $offset + 1, min($total, $offset + $bodyHeight), $total) : '';
        $footer = $message !== null && $message !== '' ? $message : '↑/↓ j/k scroll  Esc return  ? help  q quit' . $position;
        $visibleLines[] = $this->fit($footer, $width);

        return implode(PHP_EOL, $visibleLines);
    }

    private function renderHelp(int $width, int $height): string
    {
        $lines = [
            'MyTree Index Results — Help',
            str_repeat('─', $width),
            '↑/↓ or j/k   Move record selection',
            'Enter        Open selected record details',
            'Esc          Return to record list / cancel input',
            '/            Incremental free-text search',
            'f            Edit filters: type=<type> year=<YYYY|YYYY-YYYY>',
            'c            Clear search and filters',
            '?            Show this help',
            'q            Quit',
            '',
            'The viewer is read-only and offline. It reads manifest.json and records.jsonl.',
            'Raw provider responses are never parsed to reconstruct the record list.',
        ];
        while (count($lines) < $height - 1) {
            $lines[] = '';
        }
        $lines[] = 'Esc/Backspace return  q quit';

        return implode(PHP_EOL, array_map(fn (string $line): string => $this->fit($line, $width), array_slice($lines, 0, $height)));
    }

    /** @return array{0:list<string>,1:list<int>} */
    private function columns(int $width): array
    {
        if ($width >= 120) {
            return [['TYPE', 'YEAR', 'PERSON', 'PLACE', 'RELATED', 'SCAN', 'ID'], [10, 6, 28, 20, 30, 5, max(12, $width - 116)]];
        }
        if ($width >= 90) {
            return [['TYPE', 'YEAR', 'PERSON', 'PLACE', 'RELATED', 'SCAN'], [10, 6, 25, 18, max(18, $width - 72), 5]];
        }

        return [['TYPE', 'YEAR', 'PERSON', 'PLACE', 'SCAN'], [10, 6, max(18, $width - 45), 18, 5]];
    }

    /** @param list<string> $columns @return list<string> */
    private function recordCells(RecordProjection $record, array $columns): array
    {
        $map = [
            'TYPE' => $record->type,
            'YEAR' => $record->year !== null ? (string) $record->year : '—',
            'PERSON' => $record->person,
            'PLACE' => $record->place,
            'RELATED' => $record->related,
            'SCAN' => $record->hasScan ? 'yes' : '—',
            'ID' => $record->providerRecordId,
        ];

        return array_map(static fn (string $column): string => $map[$column], $columns);
    }

    /** @return list<string> */
    private function section(string $title, mixed $data, int $width): array
    {
        $lines = ['', $title];
        foreach ($this->formatValue($data) as $line) {
            $lines[] = $this->fit($line, $width);
        }
        return $lines;
    }

    /** @return list<string> */
    private function formatValue(mixed $value, int $depth = 0, ?string $key = null): array
    {
        $indent = str_repeat('  ', $depth);
        $prefix = $key === null ? '' : $key . ': ';
        if (!is_array($value)) {
            return [$indent . $prefix . $this->scalar($value)];
        }
        if ($value === []) {
            return [$indent . $prefix . '[]'];
        }

        $lines = [];
        if ($key !== null) {
            $lines[] = $indent . $key . ':';
            $depth++;
        }
        foreach ($value as $childKey => $child) {
            $lines = array_merge($lines, $this->formatValue($child, $depth, (string) $childKey));
        }
        return $lines;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function extractLocators(array $record): array
    {
        $out = [];
        $walk = static function (mixed $value, string $path = '') use (&$walk, &$out): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (in_array((string) $key, ['scan_url', 'permalink', 'source_url', 'request_url', 'raw_response_path'], true)
                    && is_string($child) && trim($child) !== '') {
                    $out[$childPath] = $child;
                }
                if (is_array($child)) {
                    $walk($child, $childPath);
                }
            }
        };
        $walk($record);

        return $out;
    }

    /** @param array<string,mixed> $map */
    private function inlineMap(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                $parts[] = $key . '=' . $this->scalar($value);
            }
        }
        return $parts === [] ? '—' : implode(' ', $parts);
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return get_debug_type($value);
    }

    private function pad(string $value, int $width): string
    {
        $truncated = $this->truncate($value, $width);
        return $truncated . str_repeat(' ', max(0, $width - $this->length($truncated)));
    }

    private function fit(string $value, int $width): string
    {
        return $this->truncate($value, $width);
    }

    private function truncate(string $value, int $width): string
    {
        if ($width <= 0 || $this->length($value) <= $width) {
            return $value;
        }
        if ($width === 1) {
            return '…';
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return substr($value, 0, max(0, $width - 1)) . '…';
        }
        return implode('', array_slice($characters, 0, $width - 1)) . '…';
    }

    private function length(string $value): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($value, 'UTF-8');
        }
        $matched = preg_match_all('/./us', $value, $matches);
        return $matched === false ? strlen($value) : $matched;
    }
}
