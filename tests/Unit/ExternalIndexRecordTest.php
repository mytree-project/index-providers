<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use MyTree\IndexProviders\Domain\ExternalIndexRecord;
use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Tests\TestCase;

final class ExternalIndexRecordTest extends TestCase
{
    public function testExplicitOtherUsesCanonicalValueWithoutSchemaChange(): void
    {
        $record = new ExternalIndexRecord(
            provider: 'example',
            providerRecordId: 'record-1',
            recordType: RecordType::Other->value,
            parish: null,
            year: null,
            fields: [],
            raw: [
                'record_type_code' => 'other',
                'record_type_label' => 'Other',
            ],
            provenance: ['provider' => 'example'],
        );

        $serialized = $record->jsonSerialize();

        self::assertSame('mytree.external-index-record.v1', $serialized['schema']);
        self::assertSame('other', $serialized['record_type']);
        self::assertSame([
            'schema',
            'provider',
            'provider_record_id',
            'record_type',
            'parish',
            'year',
            'fields',
            'raw',
            'provenance',
            'representation',
        ], array_keys($serialized));
    }

    public function testProviderQualifiedUnknownTypeRemainsOpaqueAndPreservesRawType(): void
    {
        $record = new ExternalIndexRecord(
            provider: 'example',
            providerRecordId: 'record-2',
            recordType: 'provider:example:future-category',
            parish: null,
            year: null,
            fields: [],
            raw: [
                'record_type_code' => 'X9',
                'record_type_label' => 'Future Category',
            ],
            provenance: ['provider' => 'example'],
        );

        $serialized = $record->jsonSerialize();

        self::assertSame('provider:example:future-category', $serialized['record_type']);
        self::assertNotSame(RecordType::Other->value, $serialized['record_type']);
        self::assertSame('X9', $serialized['raw']['record_type_code']);
        self::assertSame('Future Category', $serialized['raw']['record_type_label']);
    }
}
