<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Enums\MatchResult;

final readonly class MatchHistoryResponseDTO
{
    public function __construct(
        public string $id,
        public string $matchId,
        public ?MatchResult $previousResult,
        public MatchResult $newResult,
        public ?int $previousPlayer1Score,
        public ?int $newPlayer1Score,
        public ?int $previousPlayer2Score,
        public ?int $newPlayer2Score,
        public string $changedById,
        public string $changedByName,
        public ?string $reason,
        public DateTimeImmutable $changedAt,
    ) {
    }

    public static function fromEntity(
        MatchHistory $history,
        string $changedByName,
    ): self {
        return new self(
            id: $history->id(),
            matchId: $history->matchId(),
            previousResult: $history->previousResult(),
            newResult: $history->newResult(),
            previousPlayer1Score: $history->previousPlayer1Score(),
            newPlayer1Score: $history->newPlayer1Score(),
            previousPlayer2Score: $history->previousPlayer2Score(),
            newPlayer2Score: $history->newPlayer2Score(),
            changedById: $history->changedById(),
            changedByName: $changedByName,
            reason: $history->reason(),
            changedAt: $history->changedAt(),
        );
    }

    public function isInitialReport(): bool
    {
        return $this->previousResult === null;
    }

    public function resultChanged(): bool
    {
        return $this->previousResult !== $this->newResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->matchId,
            'previous_result' => $this->previousResult?->value,
            'new_result' => $this->newResult->value,
            'previous_player_1_score' => $this->previousPlayer1Score,
            'new_player_1_score' => $this->newPlayer1Score,
            'previous_player_2_score' => $this->previousPlayer2Score,
            'new_player_2_score' => $this->newPlayer2Score,
            'changed_by_id' => $this->changedById,
            'changed_by_name' => $this->changedByName,
            'reason' => $this->reason,
            'changed_at' => $this->changedAt->format('c'),
            'is_initial_report' => $this->isInitialReport(),
            'result_changed' => $this->resultChanged(),
        ];
    }
}
