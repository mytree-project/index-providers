<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use InvalidArgumentException;
use MyTree\IndexProviders\Contracts\RecordWriterInterface;
use MyTree\IndexProviders\Domain\AcquisitionStats;
use MyTree\IndexProviders\Domain\RecordType;

final class BasiaSearch
{
    private ?string $surname = null;
    private ?string $givenName = null;
    private ?int $fromYear = null;
    private ?int $toYear = null;
    private ?string $place = null;
    private int $distanceKm = 10;
    private ?RecordType $recordType = null;
    private int $similarity = 60;
    private string $sex = 'any';
    private string $relation = 'any';
    private string $unitType = 'any';
    private bool $force = false;

    public function __construct(private readonly BasiaProvider $provider)
    {
    }

    public function person(?string $surname = null, ?string $givenName = null): self
    {
        return $this->surname($surname)->givenName($givenName);
    }

    public function surname(?string $surname): self
    {
        return $this->with(static function (self $query) use ($surname): void {
            $query->surname = self::nullableTrim($surname);
        });
    }

    public function givenName(?string $givenName): self
    {
        return $this->with(static function (self $query) use ($givenName): void {
            $query->givenName = self::nullableTrim($givenName);
        });
    }

    public function years(int $from, int $to): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException('BASIA from year cannot be greater than to year.');
        }

        return $this->fromYear($from)->toYear($to);
    }

    public function fromYear(int $year): self
    {
        $this->assertYear($year);

        return $this->with(static function (self $query) use ($year): void {
            $query->fromYear = $year;
        });
    }

    public function toYear(int $year): self
    {
        $this->assertYear($year);

        return $this->with(static function (self $query) use ($year): void {
            $query->toYear = $year;
        });
    }

    public function place(?string $place, int $distanceKm = 10): self
    {
        if ($distanceKm < 0 || $distanceKm > 200) {
            throw new InvalidArgumentException('BASIA place distance must be between 0 and 200 km.');
        }

        return $this->with(static function (self $query) use ($place, $distanceKm): void {
            $query->place = self::nullableTrim($place);
            $query->distanceKm = $distanceKm;
        });
    }

    public function recordType(RecordType $type): self
    {
        if ($type === RecordType::ParishCensus) {
            throw new InvalidArgumentException(
                'Unsupported BASIA record type: parish_census. Supported values: birth, marriage, death, banns, other.',
            );
        }

        return $this->with(static function (self $query) use ($type): void {
            $query->recordType = $type;
        });
    }

    public function similarity(int $similarity): self
    {
        if ($similarity < 0 || $similarity > 100) {
            throw new InvalidArgumentException('BASIA similarity must be between 0 and 100.');
        }

        return $this->with(static function (self $query) use ($similarity): void {
            $query->similarity = $similarity;
        });
    }

    public function sex(string $sex): self
    {
        $sex = strtolower(trim($sex));
        if (!in_array($sex, ['any', 'male', 'female'], true)) {
            throw new InvalidArgumentException('Unsupported BASIA sex. Use any, male or female.');
        }

        return $this->with(static function (self $query) use ($sex): void {
            $query->sex = $sex;
        });
    }

    public function relation(string $relation): self
    {
        $relation = strtolower(trim($relation));
        if (!in_array($relation, ['any', 'parent_or_spouse', 'child', 'other'], true)) {
            throw new InvalidArgumentException('Unsupported BASIA relation. Use any, parent_or_spouse, child or other.');
        }

        return $this->with(static function (self $query) use ($relation): void {
            $query->relation = $relation;
        });
    }

    public function unitType(string $unitType): self
    {
        $unitType = strtolower(trim($unitType));
        if (!in_array($unitType, ['any', 'usc', 'catholic', 'evangelical', 'other'], true)) {
            throw new InvalidArgumentException('Unsupported BASIA unit type. Use any, usc, catholic, evangelical or other.');
        }

        return $this->with(static function (self $query) use ($unitType): void {
            $query->unitType = $unitType;
        });
    }

    public function force(bool $force = true): self
    {
        return $this->with(static function (self $query) use ($force): void {
            $query->force = $force;
        });
    }

    public function acquire(RecordWriterInterface $writer): AcquisitionStats
    {
        $this->assertReady();

        return $this->provider->executeSearch($this, $writer);
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        return [
            'surname' => $this->surname,
            'given_name' => $this->givenName,
            'from_year' => $this->fromYear,
            'to_year' => $this->toYear,
            'place' => $this->place,
            'distance_km' => $this->distanceKm,
            'record_type' => $this->recordType?->value,
            'similarity' => $this->similarity,
            'sex' => $this->sex,
            'relation' => $this->relation,
            'unit_type' => $this->unitType,
            'force' => $this->force,
        ];
    }

    /** @internal */
    public function fingerprint(): string
    {
        $this->assertReady();
        $identity = $this->configuration();
        unset($identity['force']);

        return substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 24);
    }

    /** @internal */
    public function surnameValue(): ?string
    {
        return $this->surname;
    }

    /** @internal */
    public function givenNameValue(): ?string
    {
        return $this->givenName;
    }

    /** @internal */
    public function fromYearValue(): ?int
    {
        return $this->fromYear;
    }

    /** @internal */
    public function toYearValue(): ?int
    {
        return $this->toYear;
    }

    /** @internal */
    public function placeValue(): ?string
    {
        return $this->place;
    }

    /** @internal */
    public function distanceKmValue(): int
    {
        return $this->distanceKm;
    }

    /** @internal */
    public function type(): ?RecordType
    {
        return $this->recordType;
    }

    /** @internal */
    public function similarityValue(): int
    {
        return $this->similarity;
    }

    /** @internal */
    public function sexValue(): string
    {
        return $this->sex;
    }

    /** @internal */
    public function relationValue(): string
    {
        return $this->relation;
    }

    /** @internal */
    public function unitTypeValue(): string
    {
        return $this->unitType;
    }

    /** @internal */
    public function isForced(): bool
    {
        return $this->force;
    }

    /** @internal */
    public function assertReady(): void
    {
        if ($this->surname === null && $this->givenName === null && $this->place === null) {
            throw new InvalidArgumentException('BASIA search requires at least surname, given name or place.');
        }
        if ($this->fromYear !== null && $this->toYear !== null && $this->fromYear > $this->toYear) {
            throw new InvalidArgumentException('BASIA from year cannot be greater than to year.');
        }
    }

    private function with(callable $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }

    private function assertYear(int $year): void
    {
        if ($year < 1000 || $year > 3000) {
            throw new InvalidArgumentException('BASIA year must be between 1000 and 3000.');
        }
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
