<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ScoreWeight
{
    public function __construct(
        public string $name,
        public string $key,
        public float $points,
    ) {
        $this->validate();
    }

    /**
     * @param  array{name: string, key: string, points: int|float}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            key: $data['key'],
            points: (float) $data['points'],
        );
    }

    /**
     * @return array{name: string, key: string, points: float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'key' => $this->key,
            'points' => $this->points,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->key === $other->key
            && $this->points === $other->points;
    }

    private function validate(): void
    {
        if ($this->name === '') {
            throw new InvalidArgumentException('Name cannot be empty');
        }

        if ($this->key === '') {
            throw new InvalidArgumentException('Key cannot be empty');
        }

        if ($this->points < 0) {
            throw new InvalidArgumentException('Points cannot be negative');
        }
    }
}
