<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

final class RecordProjector
{
    /** @param array<string,mixed> $record */
    public function project(array $record): RecordProjection
    {
        $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];

        return new RecordProjection(
            record: $record,
            type: (string) ($record['record_type'] ?? ''),
            year: is_int($record['year'] ?? null) ? $record['year'] : null,
            person: $this->personLabel($fields, $record),
            place: $this->placeLabel($fields, $record),
            related: $this->relatedLabel($fields),
            similarity: is_int($fields['similarity_percent'] ?? null) ? $fields['similarity_percent'] : null,
            hasScan: $this->containsScanLocator($record),
            providerRecordId: (string) ($record['provider_record_id'] ?? ''),
            searchText: $this->fold($this->searchableText($record)),
        );
    }

    public function fold(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower(strtr($value, [
            'Ą' => 'ą', 'Ć' => 'ć', 'Ę' => 'ę', 'Ł' => 'ł', 'Ń' => 'ń',
            'Ó' => 'ó', 'Ś' => 'ś', 'Ź' => 'ź', 'Ż' => 'ż',
        ]));
    }

    /** @param array<string,mixed> $fields @param array<string,mixed> $record */
    private function personLabel(array $fields, array $record): string
    {
        foreach (['person', 'child', 'deceased'] as $key) {
            if (is_array($fields[$key] ?? null)) {
                $label = $this->personFromArray($fields[$key]);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        if (is_array($fields['groom'] ?? null) || is_array($fields['bride'] ?? null)) {
            $groom = is_array($fields['groom'] ?? null) ? $this->personFromArray($fields['groom']) : '';
            $bride = is_array($fields['bride'] ?? null) ? $this->personFromArray($fields['bride']) : '';
            $couple = implode(' × ', array_values(array_filter([$groom, $bride], static fn (string $v): bool => $v !== '')));
            if ($couple !== '') {
                return $couple;
            }
        }

        if (is_string($fields['personalia_raw'] ?? null) && trim($fields['personalia_raw']) !== '') {
            return trim($fields['personalia_raw']);
        }

        $raw = is_array($record['raw'] ?? null) ? $record['raw'] : [];
        if (is_string($raw['name'] ?? null) && trim($raw['name']) !== '') {
            return trim($raw['name']);
        }

        return '(unnamed)';
    }

    /** @param array<string,mixed> $person */
    private function personFromArray(array $person): string
    {
        if (is_string($person['name_raw'] ?? null) && trim($person['name_raw']) !== '') {
            return trim($person['name_raw']);
        }

        $given = '';
        foreach (['given_names_raw', 'given_name_raw'] as $key) {
            if (is_string($person[$key] ?? null) && trim($person[$key]) !== '') {
                $given = trim($person[$key]);
                break;
            }
        }

        $surname = is_string($person['surname_raw'] ?? null) ? trim($person['surname_raw']) : '';
        return trim($given . ' ' . $surname);
    }

    /** @param array<string,mixed> $fields @param array<string,mixed> $record */
    private function placeLabel(array $fields, array $record): string
    {
        foreach (['place_raw', 'parish_raw'] as $key) {
            if (is_string($fields[$key] ?? null) && trim($fields[$key]) !== '') {
                return trim($fields[$key]);
            }
        }

        return is_string($record['parish'] ?? null) && trim($record['parish']) !== '' ? trim($record['parish']) : '—';
    }

    /** @param array<string,mixed> $fields */
    private function relatedLabel(array $fields): string
    {
        $parts = [];
        $person = is_array($fields['person'] ?? null) ? $fields['person'] : [];
        foreach (['parents_raw', 'father_raw', 'mother_raw', 'spouse_raw', 'father_given_names_raw', 'mother_given_names_raw', 'mother_surname_raw'] as $key) {
            if (is_string($person[$key] ?? null) && trim($person[$key]) !== '') {
                $parts[] = trim($person[$key]);
            }
        }

        if (is_array($fields['other_persons_raw'] ?? null)) {
            foreach ($fields['other_persons_raw'] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $parts[] = trim($value);
                }
            }
        }

        foreach (['father_given_names_raw', 'mother_given_names_raw', 'mother_surname_raw', 'family_and_notes_raw'] as $key) {
            if (is_string($fields[$key] ?? null) && trim($fields[$key]) !== '') {
                $parts[] = trim($fields[$key]);
            }
        }

        foreach (['groom', 'bride'] as $key) {
            if (!is_array($fields[$key] ?? null)) {
                continue;
            }
            foreach (['parents_raw', 'father_given_names_raw', 'mother_given_names_raw', 'mother_surname_raw'] as $relatedKey) {
                if (is_string($fields[$key][$relatedKey] ?? null) && trim($fields[$key][$relatedKey]) !== '') {
                    $parts[] = trim($fields[$key][$relatedKey]);
                }
            }
        }

        return $parts === [] ? '—' : implode('; ', array_values(array_unique($parts)));
    }

    /** @param array<string,mixed> $value */
    private function containsScanLocator(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (in_array($key, ['scan_url', 'scan_label_raw', 'scan_number_raw'], true) && is_scalar($item) && trim((string) $item) !== '') {
                return true;
            }
            if (is_array($item) && $this->containsScanLocator($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $record */
    private function searchableText(array $record): string
    {
        $searchable = [
            'provider' => $record['provider'] ?? null,
            'provider_record_id' => $record['provider_record_id'] ?? null,
            'record_type' => $record['record_type'] ?? null,
            'parish' => $record['parish'] ?? null,
            'year' => $record['year'] ?? null,
            'fields' => $record['fields'] ?? [],
            'raw' => $record['raw'] ?? [],
        ];

        return $this->flattenScalars($searchable);
    }

    private function flattenScalars(mixed $value): string
    {
        $parts = [];
        $walk = static function (mixed $item) use (&$walk, &$parts): void {
            if (is_array($item)) {
                foreach ($item as $nested) {
                    $walk($nested);
                }
                return;
            }
            if (is_string($item) || is_int($item) || is_float($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        };
        $walk($value);

        return implode(' ', $parts);
    }
}
