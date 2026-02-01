<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ScoringRule
{
    public function __construct(
        public string $name,
        public ScoringCondition $condition,
        public float $points,
        public int $priority = 0,
    ) {
        $this->validate();
    }

    /**
     * @param  array{name: string, condition: array{type: string, result_value: string|null, stat: string|null, operator: string|null, value: int|float|null}, points: int|float, priority?: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            condition: ScoringCondition::fromArray($data['condition']),
            points: (float) $data['points'],
            priority: $data['priority'] ?? 0,
        );
    }

    /**
     * @return array{name: string, condition: array{type: string, result_value: string|null, stat: string|null, operator: string|null, value: float|null}, points: float, priority: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'condition' => $this->condition->toArray(),
            'points' => $this->points,
            'priority' => $this->priority,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->condition->equals($other->condition)
            && $this->points === $other->points
            && $this->priority === $other->priority;
    }

    private function validate(): void
    {
        if ($this->name === '') {
            throw new InvalidArgumentException('Name cannot be empty');
        }

        if ($this->points < 0) {
            throw new InvalidArgumentException('Points cannot be negative');
        }
    }
}
