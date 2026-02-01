<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Ramsey\Uuid\Uuid;

final readonly class MatchHistory
{
    public function __construct(
        private string $id,
        private string $matchId,
        private ?MatchResult $previousResult,
        private MatchResult $newResult,
        private ?int $previousPlayer1Score,
        private ?int $newPlayer1Score,
        private ?int $previousPlayer2Score,
        private ?int $newPlayer2Score,
        private string $changedById,
        private ?string $reason,
        private DateTimeImmutable $changedAt,
    ) {
    }

    public static function fromResultChange(
        string $matchId,
        ?MatchResult $previousResult,
        MatchResult $newResult,
        ?int $previousPlayer1Score,
        ?int $newPlayer1Score,
        ?int $previousPlayer2Score,
        ?int $newPlayer2Score,
        string $changedById,
        ?string $reason,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            matchId: $matchId,
            previousResult: $previousResult,
            newResult: $newResult,
            previousPlayer1Score: $previousPlayer1Score,
            newPlayer1Score: $newPlayer1Score,
            previousPlayer2Score: $previousPlayer2Score,
            newPlayer2Score: $newPlayer2Score,
            changedById: $changedById,
            reason: $reason,
            changedAt: new DateTimeImmutable(),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function matchId(): string
    {
        return $this->matchId;
    }

    public function previousResult(): ?MatchResult
    {
        return $this->previousResult;
    }

    public function newResult(): MatchResult
    {
        return $this->newResult;
    }

    public function previousPlayer1Score(): ?int
    {
        return $this->previousPlayer1Score;
    }

    public function newPlayer1Score(): ?int
    {
        return $this->newPlayer1Score;
    }

    public function previousPlayer2Score(): ?int
    {
        return $this->previousPlayer2Score;
    }

    public function newPlayer2Score(): ?int
    {
        return $this->newPlayer2Score;
    }

    public function changedById(): string
    {
        return $this->changedById;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function changedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }
}
