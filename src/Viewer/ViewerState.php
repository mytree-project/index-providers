<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

use InvalidArgumentException;

final class ViewerState
{
    /** @var list<RecordProjection> */
    private array $records;

    private int $selected = 0;
    private string $search = '';
    private ?string $type = null;
    private ?int $fromYear = null;
    private ?int $toYear = null;
    private string $view = 'list';
    private int $detailOffset = 0;

    /** @param list<RecordProjection> $records */
    public function __construct(array $records, private readonly RecordProjector $projector = new RecordProjector())
    {
        $this->records = array_values($records);
    }

    public function search(): string
    {
        return $this->search;
    }

    public function setSearch(string $search): void
    {
        $this->search = trim($search);
        $this->selected = 0;
    }

    public function filterExpression(): string
    {
        $parts = [];
        if ($this->type !== null) {
            $parts[] = 'type=' . $this->type;
        }
        if ($this->fromYear !== null) {
            $parts[] = $this->toYear !== null && $this->toYear !== $this->fromYear
                ? 'year=' . $this->fromYear . '-' . $this->toYear
                : 'year=' . $this->fromYear;
        }

        return implode(' ', $parts);
    }

    public function setFilterExpression(string $expression): void
    {
        $type = null;
        $from = null;
        $to = null;
        $expression = trim($expression);

        if ($expression !== '') {
            foreach (preg_split('/\s+/', $expression) ?: [] as $token) {
                if (str_starts_with($token, 'type=')) {
                    $value = trim(substr($token, 5));
                    if ($value === '') {
                        throw new InvalidArgumentException('Filter type must not be empty.');
                    }
                    $type = strtolower($value);
                    continue;
                }

                if (str_starts_with($token, 'year=')) {
                    $value = trim(substr($token, 5));
                    if (preg_match('/^(\d{4})(?:-(\d{4}))?$/', $value, $m) !== 1) {
                        throw new InvalidArgumentException('Year filter must use year=YYYY or year=YYYY-YYYY.');
                    }
                    $from = (int) $m[1];
                    $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $from;
                    if ($to < $from) {
                        throw new InvalidArgumentException('Year filter end must not be before start.');
                    }
                    continue;
                }

                throw new InvalidArgumentException("Unknown filter token: $token");
            }
        }

        $this->type = $type;
        $this->fromYear = $from;
        $this->toYear = $to;
        $this->selected = 0;
    }

    public function clearFilters(): void
    {
        $this->type = null;
        $this->fromYear = null;
        $this->toYear = null;
        $this->selected = 0;
    }

    public function clearAll(): void
    {
        $this->search = '';
        $this->clearFilters();
    }

    /** @return list<RecordProjection> */
    public function visibleRecords(): array
    {
        $needle = $this->projector->fold($this->search);

        return array_values(array_filter($this->records, function (RecordProjection $record) use ($needle): bool {
            if ($needle !== '' && !str_contains($record->searchText, $needle)) {
                return false;
            }
            if ($this->type !== null && $record->type !== $this->type) {
                return false;
            }
            if ($this->fromYear !== null) {
                if ($record->year === null || $record->year < $this->fromYear || $record->year > ($this->toYear ?? $this->fromYear)) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function move(int $delta): void
    {
        $count = count($this->visibleRecords());
        if ($count === 0) {
            $this->selected = 0;
            return;
        }
        $this->selected = max(0, min($count - 1, $this->selected + $delta));
    }

    public function current(): ?RecordProjection
    {
        $visible = $this->visibleRecords();
        return $visible[$this->selected] ?? null;
    }

    public function openDetails(): void
    {
        if ($this->current() !== null) {
            $this->view = 'details';
            $this->detailOffset = 0;
        }
    }

    public function showHelp(): void
    {
        $this->view = 'help';
    }

    public function showList(): void
    {
        $this->view = 'list';
        $this->detailOffset = 0;
    }

    public function detailOffset(): int
    {
        return $this->detailOffset;
    }

    public function scrollDetails(int $delta): void
    {
        $this->detailOffset = max(0, $this->detailOffset + $delta);
    }

    public function view(): string
    {
        return $this->view;
    }
}
