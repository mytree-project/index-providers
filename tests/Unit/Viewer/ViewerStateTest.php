<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit\Viewer;

use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Viewer\RecordProjector;
use MyTree\IndexProviders\Viewer\ViewerState;

final class ViewerStateTest extends TestCase
{
    public function testSearchTypeAndYearFiltersAreComposable(): void
    {
        $projector = new RecordProjector();
        $state = new ViewerState([
            $projector->project($this->record('1', 'birth', 1863, 'Walenty Wiśniewski')),
            $projector->project($this->record('2', 'death', 1863, 'Walenty Wiśniewski')),
            $projector->project($this->record('3', 'birth', 1864, 'Jan Kowalski')),
        ], $projector);

        $state->setSearch('WIŚNIEWSKI');
        self::assertCount(2, $state->visibleRecords());

        $state->setFilterExpression('type=birth year=1863-1864');
        $visible = $state->visibleRecords();
        self::assertCount(1, $visible);
        self::assertSame('1', $visible[0]->providerRecordId);

        $state->clearAll();
        self::assertCount(3, $state->visibleRecords());
    }

    public function testNavigationAndDetailsStateAreIndependentOfTerminal(): void
    {
        $projector = new RecordProjector();
        $state = new ViewerState([
            $projector->project($this->record('1', 'birth', 1863, 'A')),
            $projector->project($this->record('2', 'birth', 1864, 'B')),
        ], $projector);

        $state->move(1);
        self::assertSame('2', $state->current()?->providerRecordId);
        $state->move(99);
        self::assertSame(1, $state->selectedIndex());

        $state->openDetails();
        self::assertSame('details', $state->view());
        $state->scrollDetails(4);
        self::assertSame(4, $state->detailOffset());
        $state->showList();
        self::assertSame(0, $state->detailOffset());
    }

    /** @return array<string,mixed> */
    private function record(string $id, string $type, int $year, string $name): array
    {
        return [
            'schema' => 'mytree.external-index-record.v1',
            'provider' => 'fixture',
            'provider_record_id' => $id,
            'record_type' => $type,
            'parish' => 'Test',
            'year' => $year,
            'fields' => ['person' => ['name_raw' => $name]],
            'raw' => [],
            'provenance' => ['query' => ['surname' => 'ignored']],
            'representation' => null,
        ];
    }
}
