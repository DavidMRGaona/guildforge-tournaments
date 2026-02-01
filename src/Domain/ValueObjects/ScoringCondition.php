<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ConditionType;

final readonly class ScoringCondition
{
    private const array VALID_RESULT_VALUES = ['win', 'draw', 'loss', 'bye'];

    public function __construct(
        public ConditionType $type,
        public ?string $resultValue,
        public ?string $stat,
        public ?string $operator,
        public ?float $value,
    ) {
        $this->validate();
    }

    /**
     * @param  array{type: string, result_value: ?string, stat: ?string, operator: ?string, value: int|float|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: ConditionType::from($data['type']),
            resultValue: $data['result_value'],
            stat: $data['stat'],
            operator: $data['operator'],
            value: $data['value'] !== null ? (float) $data['value'] : null,
        );
    }

    /**
     * @return array{type: string, result_value: ?string, stat: ?string, operator: ?string, value: ?float}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'result_value' => $this->resultValue,
            'stat' => $this->stat,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->resultValue === $other->resultValue
            && $this->stat === $other->stat
            && $this->operator === $other->operator
            && $this->value === $other->value;
    }

    private function validate(): void
    {
        match ($this->type) {
            ConditionType::Result => $this->validateResult(),
            ConditionType::StatComparison => $this->validateStatComparison(),
            ConditionType::StatThreshold => $this->validateStatThreshold(),
            ConditionType::MarginDifference => $this->validateMarginDifference(),
        };
    }

    private function validateResult(): void
    {
        if ($this->resultValue === null) {
            throw new InvalidArgumentException('Result type requires resultValue');
        }

        if (! in_array($this->resultValue, self::VALID_RESULT_VALUES, true)) {
            throw new InvalidArgumentException(
                'Invalid result value. Must be one of: ' . implode(', ', self::VALID_RESULT_VALUES)
            );
        }
    }

    private function validateStatComparison(): void
    {
        if ($this->stat === null || $this->operator === null) {
            throw new InvalidArgumentException('StatComparison type requires stat and operator');
        }
    }

    private function validateStatThreshold(): void
    {
        if ($this->stat === null || $this->operator === null || $this->value === null) {
            throw new InvalidArgumentException('StatThreshold type requires stat, operator, and value');
        }
    }

    private function validateMarginDifference(): void
    {
        if ($this->stat === null || $this->operator === null || $this->value === null) {
            throw new InvalidArgumentException('MarginDifference type requires stat, operator, and value');
        }
    }
}
