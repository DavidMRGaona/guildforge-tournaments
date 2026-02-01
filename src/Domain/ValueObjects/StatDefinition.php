<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\StatType;

final readonly class StatDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public StatType $type,
        public ?int $minValue = null,
        public ?int $maxValue = null,
        public bool $perPlayer = true,
        public bool $required = false,
        public ?string $description = null,
    ) {
        $this->validate();
    }

    /**
     * @param  array{key: string, name: string, type: string, min_value?: int|null, max_value?: int|null, per_player?: bool, required?: bool, description?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            name: $data['name'],
            type: StatType::from($data['type']),
            minValue: $data['min_value'] ?? null,
            maxValue: $data['max_value'] ?? null,
            perPlayer: $data['per_player'] ?? true,
            required: $data['required'] ?? false,
            description: $data['description'] ?? null,
        );
    }

    /**
     * @return array{key: string, name: string, type: string, min_value: int|null, max_value: int|null, per_player: bool, required: bool, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'min_value' => $this->minValue,
            'max_value' => $this->maxValue,
            'per_player' => $this->perPlayer,
            'required' => $this->required,
            'description' => $this->description,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key
            && $this->name === $other->name
            && $this->type === $other->type
            && $this->minValue === $other->minValue
            && $this->maxValue === $other->maxValue
            && $this->perPlayer === $other->perPlayer
            && $this->required === $other->required
            && $this->description === $other->description;
    }

    private function validate(): void
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('Key cannot be empty');
        }

        if ($this->name === '') {
            throw new InvalidArgumentException('Name cannot be empty');
        }

        if ($this->minValue !== null && $this->maxValue !== null && $this->minValue > $this->maxValue) {
            throw new InvalidArgumentException('Minimum value cannot be greater than maximum value');
        }
    }
}
