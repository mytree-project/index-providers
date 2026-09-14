<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Unit;

use MyTree\IndexProviders\Domain\RecordType;
use MyTree\IndexProviders\Tests\TestCase;

final class RecordTypeTest extends TestCase
{
    public function testCanonicalValuesIncludeBasiaCompatibleTypes(): void
    {
        self::assertSame(
            ['birth', 'marriage', 'death', 'banns', 'parish_census', 'other'],
            array_map(static fn (RecordType $type): string => $type->value, RecordType::cases()),
        );
    }

    public function testBannsRemainDistinctFromMarriage(): void
    {
        self::assertNotSame(RecordType::Marriage->value, RecordType::Banns->value);
        self::assertSame('banns', RecordType::Banns->value);
    }

    public function testOtherRepresentsAnExplicitCanonicalValue(): void
    {
        self::assertSame('other', RecordType::Other->value);
    }
}
