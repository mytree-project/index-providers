<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Domain;

enum RecordType: string
{
    case Birth = 'birth';
    case Marriage = 'marriage';
    case Death = 'death';
    case Banns = 'banns';
    case ParishCensus = 'parish_census';
    case Other = 'other';
}
