<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Provider;

use InvalidArgumentException;
use MyTree\IndexProviders\Contracts\RecordWriterInterface;
use MyTree\IndexProviders\Domain\AcquisitionStats;
use MyTree\IndexProviders\Domain\RecordType;

final class GenetekaAcquisition
{
    /** @var array<string,string|int> */
    private array $formParameters = [];

    private ?string $region = null;
    private ?string $parishId = null;
    private ?string $parishName = null;
    private ?RecordType $recordType = null;
    private int $pageSize = 50;
    private bool $force = false;

    /** @var list<string> */
    private const RESERVED_PARAMETERS = ['bdm', 'w', 'rid', 'length', 'start'];

    public function __construct(private readonly GenetekaProvider $provider)
    {
    }

    public function region(string $region): self
    {
        $region = trim($region);
        if ($region === '') {
            throw new InvalidArgumentException('Geneteka region cannot be empty.');
        }
        return $this->with(static function (self $query) use ($region): void {
            $query->region = $region;
        });
    }

    public function parish(string $providerParishId, ?string $name = null): self
    {
        $providerParishId = trim($providerParishId);
        if ($providerParishId === '') {
            throw new InvalidArgumentException('Geneteka parish id cannot be empty.');
        }
        $name = $name !== null ? trim($name) : null;
        return $this->with(static function (self $query) use ($providerParishId, $name): void {
            $query->parishId = $providerParishId;
            $query->parishName = $name !== '' ? $name : null;
        });
    }

    public function recordType(RecordType $type): self
    {
        if (!in_array($type, [RecordType::Birth, RecordType::Marriage, RecordType::Death], true)) {
            throw new InvalidArgumentException(
                'Unsupported Geneteka record type: ' . $type->value . '. Supported values: birth, marriage, death.',
            );
        }
        return $this->with(static function (self $query) use ($type): void {
            $query->recordType = $type;
        });
    }

    public function record(GenetekaRecordAvailability $availability): self
    {
        return $this
            ->parish($availability->providerParishId, $this->parishName)
            ->recordType($availability->recordType);
    }

    public function person(?string $surname = null, ?string $givenName = null): self
    {
        return $this
            ->formParameter('search_lastname', $surname)
            ->formParameter('search_name', $givenName);
    }

    public function secondPerson(?string $surname = null, ?string $givenName = null): self
    {
        return $this
            ->formParameter('search_lastname2', $surname)
            ->formParameter('search_name2', $givenName);
    }

    public function years(int $from, int $to): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException('Geneteka from year cannot be greater than to year.');
        }
        return $this->fromYear($from)->toYear($to);
    }

    public function fromYear(int $year): self
    {
        $this->assertYear($year);
        return $this->formParameter('from_date', $year);
    }

    public function toYear(int $year): self
    {
        $this->assertYear($year);
        return $this->formParameter('to_date', $year);
    }

    public function exact(bool $enabled = true): self
    {
        return $this->formParameter('exac', $enabled ? 1 : null);
    }

    public function excludeParents(bool $enabled = true): self
    {
        return $this->formParameter('parents', $enabled ? 1 : null);
    }

    public function formParameter(string $name, string|int|bool|null $value): self
    {
        $name = trim($name);
        if ($name === '' || !preg_match('~^[A-Za-z0-9_]+$~', $name)) {
            throw new InvalidArgumentException('Invalid Geneteka form parameter name.');
        }
        if (in_array($name, self::RESERVED_PARAMETERS, true)) {
            throw new InvalidArgumentException("Geneteka form parameter '$name' is reserved by the provider transport.");
        }

        return $this->with(static function (self $query) use ($name, $value): void {
            if ($value === null || $value === false || (is_string($value) && trim($value) === '')) {
                unset($query->formParameters[$name]);
                return;
            }
            $query->formParameters[$name] = $value === true ? 1 : $value;
            ksort($query->formParameters);
        });
    }

    /** @param array<string,string|int|bool|null> $parameters */
    public function formParameters(array $parameters): self
    {
        $query = $this;
        foreach ($parameters as $name => $value) {
            $query = $query->formParameter((string) $name, $value);
        }
        return $query;
    }

    public function pageSize(int $pageSize): self
    {
        if ($pageSize < 1 || $pageSize > 500) {
            throw new InvalidArgumentException('Geneteka page size must be between 1 and 500.');
        }
        return $this->with(static function (self $query) use ($pageSize): void {
            $query->pageSize = $pageSize;
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
        return $this->provider->executeAcquisition($this, $writer);
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        return [
            'region' => $this->region,
            'parish_id' => $this->parishId,
            'parish_name' => $this->parishName,
            'record_type' => $this->recordType?->value,
            'form_parameters' => $this->formParameters,
            'page_size' => $this->pageSize,
            'force' => $this->force,
        ];
    }

    /** @internal */
    public function regionCode(): string
    {
        $this->assertReady();
        return (string) $this->region;
    }

    /** @internal */
    public function providerParishId(): string
    {
        $this->assertReady();
        return (string) $this->parishId;
    }

    /** @internal */
    public function parishName(): ?string
    {
        return $this->parishName;
    }

    /** @internal */
    public function type(): RecordType
    {
        $this->assertReady();
        return $this->recordType;
    }

    /** @return array<string,string|int> @internal */
    public function providerFormParameters(): array
    {
        return $this->formParameters;
    }

    /** @internal */
    public function configuredPageSize(): int
    {
        return $this->pageSize;
    }

    /** @internal */
    public function isForced(): bool
    {
        return $this->force;
    }

    /** @internal */
    public function fingerprint(): string
    {
        $this->assertReady();
        $identity = [
            'region' => $this->region,
            'parish_id' => $this->parishId,
            'record_type' => $this->recordType?->value,
            'form_parameters' => $this->formParameters,
            'page_size' => $this->pageSize,
        ];
        return substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 24);
    }

    private function with(callable $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);
        return $clone;
    }

    private function assertReady(): void
    {
        if ($this->region === null || $this->parishId === null || $this->recordType === null) {
            throw new InvalidArgumentException('Geneteka acquisition requires region, parish and record type.');
        }
        $from = isset($this->formParameters['from_date']) ? (int) $this->formParameters['from_date'] : null;
        $to = isset($this->formParameters['to_date']) ? (int) $this->formParameters['to_date'] : null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Geneteka from year cannot be greater than to year.');
        }
    }

    private function assertYear(int $year): void
    {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException('Geneteka year must be between 1 and 9999.');
        }
    }
}
