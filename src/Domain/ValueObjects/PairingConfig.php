<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;

final readonly class PairingConfig
{
    public function __construct(
        public PairingMethod $method = PairingMethod::Swiss,
        public PairingSortCriteria $sortBy = PairingSortCriteria::Points,
        public ?string $sortByStat = null,
        public bool $avoidRematches = true,
        public int $maxByesPerPlayer = 1,
        public ByeAssignment $byeAssignment = ByeAssignment::LowestRanked,
    ) {
        $this->validate();
    }

    /**
     * @param  array{method?: string, sort_by?: string, sort_by_stat?: string|null, avoid_rematches?: bool, max_byes_per_player?: int, bye_assignment?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: isset($data['method']) ? PairingMethod::from($data['method']) : PairingMethod::Swiss,
            sortBy: isset($data['sort_by']) ? PairingSortCriteria::from($data['sort_by']) : PairingSortCriteria::Points,
            sortByStat: $data['sort_by_stat'] ?? null,
            avoidRematches: $data['avoid_rematches'] ?? true,
            maxByesPerPlayer: $data['max_byes_per_player'] ?? 1,
            byeAssignment: isset($data['bye_assignment']) ? ByeAssignment::from($data['bye_assignment']) : ByeAssignment::LowestRanked,
        );
    }

    /**
     * @return array{method: string, sort_by: string, sort_by_stat: string|null, avoid_rematches: bool, max_byes_per_player: int, bye_assignment: string}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'sort_by' => $this->sortBy->value,
            'sort_by_stat' => $this->sortByStat,
            'avoid_rematches' => $this->avoidRematches,
            'max_byes_per_player' => $this->maxByesPerPlayer,
            'bye_assignment' => $this->byeAssignment->value,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->method === $other->method
            && $this->sortBy === $other->sortBy
            && $this->sortByStat === $other->sortByStat
            && $this->avoidRematches === $other->avoidRematches
            && $this->maxByesPerPlayer === $other->maxByesPerPlayer
            && $this->byeAssignment === $other->byeAssignment;
    }

    private function validate(): void
    {
        if ($this->sortBy === PairingSortCriteria::Stat && ($this->sortByStat === null || $this->sortByStat === '')) {
            throw new InvalidArgumentException('sortByStat is required when sortBy is Stat');
        }

        if ($this->maxByesPerPlayer < 0) {
            throw new InvalidArgumentException('maxByesPerPlayer cannot be negative');
        }
    }
}
