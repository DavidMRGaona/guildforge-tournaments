<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\TiebreakerType;

final readonly class TiebreakerDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public TiebreakerType $type,
        public ?string $stat = null,
        public SortDirection $direction = SortDirection::Descending,
        public ?float $minValue = null,
    ) {
        $this->validate();
    }

    /**
     * @param  array{key: string, name: string, type: string, stat?: string|null, direction?: string, min_value?: float|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            name: $data['name'],
            type: TiebreakerType::from($data['type']),
            stat: $data['stat'] ?? null,
            direction: isset($data['direction'])
                ? SortDirection::from($data['direction'])
                : SortDirection::Descending,
            minValue: $data['min_value'] ?? null,
        );
    }

    /**
     * @return array{key: string, name: string, type: string, stat: string|null, direction: string, min_value: float|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'stat' => $this->stat,
            'direction' => $this->direction->value,
            'min_value' => $this->minValue,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key
            && $this->name === $other->name
            && $this->type === $other->type
            && $this->stat === $other->stat
            && $this->direction === $other->direction
            && $this->minValue === $other->minValue;
    }

    private function validate(): void
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('Key cannot be empty');
        }

        if ($this->name === '') {
            throw new InvalidArgumentException('Name cannot be empty');
        }

        if ($this->type->requiresStat() && $this->stat === null) {
            throw new InvalidArgumentException('Stat is required for stat-based tiebreaker types');
        }
    }
}
