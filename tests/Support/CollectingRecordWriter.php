<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Tests\Support;

use MyTree\IndexProviders\Contracts\RecordWriterInterface;
use MyTree\IndexProviders\Domain\ExternalIndexRecord;

final class CollectingRecordWriter implements RecordWriterInterface
{
    /** @var list<ExternalIndexRecord> */
    public array $records = [];

    public function write(ExternalIndexRecord $record): void
    {
        $this->records[] = $record;
    }

    public function close(): void
    {
    }
}
