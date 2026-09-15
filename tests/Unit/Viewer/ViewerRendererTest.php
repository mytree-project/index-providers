<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit\Viewer;

use MyTree\IndexProviders\Tests\TestCase;
use MyTree\IndexProviders\Viewer\AcquisitionRun;
use MyTree\IndexProviders\Viewer\RecordProjector;
use MyTree\IndexProviders\Viewer\ViewerRenderer;
use MyTree\IndexProviders\Viewer\ViewerState;

final class ViewerRendererTest extends TestCase
{
    public function testDetailsExposeStructuredRawProvenanceAndLocators(): void
    {
        $record = [
            'schema' => 'mytree.external-index-record.v1',
            'provider' => 'basia',
            'provider_record_id' => '350428',
            'record_type' => 'birth',
            'parish' => null,
            'year' => 1863,
            'fields' => [
                'person' => ['name_raw' => 'Jacobus'],
                'place_raw' => 'Kozielsko',
                'scan_url' => 'https://example.test/scan',
            ],
            'raw' => ['record_type_label' => 'akt urodzenia/chrztu'],
            'provenance' => [
                'source_url' => 'https://example.test/record',
                'raw_response_path' => 'raw/basia/query.html',
                'nested' => ['parser_version' => '1'],
            ],
            'representation' => ['kind' => 'indexer_rendering'],
        ];
        $run = new AcquisitionRun('/tmp/run', [
            'schema' => 'mytree.index-acquisition-manifest.v1',
            'provider' => 'basia',
            'stats' => ['records' => 1],
        ], [$record]);
        $projector = new RecordProjector();
        $state = new ViewerState([$projector->project($record)], $projector);
        $state->openDetails();

        $screen = (new ViewerRenderer())->render($run, $state, 160, 80);

        self::assertStringContainsString('IDENTITY', $screen);
        self::assertStringContainsString('LOCATORS', $screen);
        self::assertStringContainsString('RAW PROVIDER VALUES', $screen);
        self::assertStringContainsString('PROVENANCE', $screen);
        self::assertStringContainsString('REPRESENTATION', $screen);
        self::assertStringContainsString('raw_response_path: raw/basia/query.html', $screen);
        self::assertStringContainsString('parser_version: 1', $screen);
    }
}
