<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\RoundStatus;

final readonly class RoundResponseDTO
{
    public function __construct(
        public string $id,
        public string $tournamentId,
        public int $roundNumber,
        public RoundStatus $status,
        public int $matchCount,
        public int $completedMatchCount,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromEntity(
        Round $round,
        int $matchCount = 0,
        int $completedMatchCount = 0,
    ): self {
        return new self(
            id: $round->id()->value,
            tournamentId: $round->tournamentId(),
            roundNumber: $round->roundNumber(),
            status: $round->status(),
            matchCount: $matchCount,
            completedMatchCount: $completedMatchCount,
            startedAt: $round->startedAt(),
            completedAt: $round->completedAt(),
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === RoundStatus::Finished;
    }

    public function pendingMatchCount(): int
    {
        return $this->matchCount - $this->completedMatchCount;
    }

    public function completionPercentage(): float
    {
        if ($this->matchCount === 0) {
            return 0.0;
        }

        return ($this->completedMatchCount / $this->matchCount) * 100;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournamentId,
            'round_number' => $this->roundNumber,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'match_count' => $this->matchCount,
            'completed_match_count' => $this->completedMatchCount,
            'pending_match_count' => $this->pendingMatchCount(),
            'completion_percentage' => $this->completionPercentage(),
            'is_completed' => $this->isCompleted(),
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
        ];
    }
}
