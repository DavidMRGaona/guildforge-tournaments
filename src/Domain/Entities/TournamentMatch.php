<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\ValueObjects\MatchId;

final class TournamentMatch
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function __construct(
        private readonly MatchId $id,
        private readonly string $roundId,
        private readonly string $player1Id,
        private readonly ?string $player2Id,
        private MatchResult $result,
        private ?int $tableNumber = null,
        private ?int $player1Score = null,
        private ?int $player2Score = null,
        private ?array $player1Stats = null,
        private ?array $player2Stats = null,
        private ?string $reportedById = null,
        private ?DateTimeImmutable $reportedAt = null,
        private ?string $confirmedById = null,
        private ?DateTimeImmutable $confirmedAt = null,
        private bool $isDisputed = false,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): MatchId
    {
        return $this->id;
    }

    public function roundId(): string
    {
        return $this->roundId;
    }

    public function player1Id(): string
    {
        return $this->player1Id;
    }

    public function player2Id(): ?string
    {
        return $this->player2Id;
    }

    public function result(): MatchResult
    {
        return $this->result;
    }

    public function tableNumber(): ?int
    {
        return $this->tableNumber;
    }

    public function player1Score(): ?int
    {
        return $this->player1Score;
    }

    public function player2Score(): ?int
    {
        return $this->player2Score;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function player1Stats(): ?array
    {
        return $this->player1Stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function player2Stats(): ?array
    {
        return $this->player2Stats;
    }

    public function reportedById(): ?string
    {
        return $this->reportedById;
    }

    public function reportedAt(): ?DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function confirmedById(): ?string
    {
        return $this->confirmedById;
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function isDisputed(): bool
    {
        return $this->isDisputed;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Check if this is a bye match (player 2 is null).
     */
    public function isBye(): bool
    {
        return $this->player2Id === null;
    }

    /**
     * Check if the match has been completed (result reported).
     */
    public function isCompleted(): bool
    {
        return $this->result->isCompleted();
    }

    /**
     * Check if the result has been confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmedById !== null;
    }

    /**
     * Check if a participant is involved in this match.
     */
    public function involvesParticipant(string $participantId): bool
    {
        return $this->player1Id === $participantId || $this->player2Id === $participantId;
    }

    /**
     * Report the match result.
     */
    public function reportResult(
        MatchResult $result,
        string $reportedById,
        ?int $player1Score = null,
        ?int $player2Score = null,
    ): void {
        $this->result = $result;
        $this->reportedById = $reportedById;
        $this->reportedAt = new DateTimeImmutable();
        $this->player1Score = $player1Score;
        $this->player2Score = $player2Score;
    }

    /**
     * Confirm the reported result.
     */
    public function confirmResult(string $confirmedById): void
    {
        $this->confirmedById = $confirmedById;
        $this->confirmedAt = new DateTimeImmutable();
        $this->isDisputed = false;
    }

    /**
     * Dispute the reported result.
     */
    public function dispute(): void
    {
        $this->isDisputed = true;
    }

    /**
     * Reset the match result (admin action).
     */
    public function resetResult(): void
    {
        $this->result = MatchResult::NotPlayed;
        $this->player1Score = null;
        $this->player2Score = null;
        $this->reportedById = null;
        $this->reportedAt = null;
        $this->confirmedById = null;
        $this->confirmedAt = null;
        $this->isDisputed = false;
    }

    /**
     * Set the table number for this match.
     */
    public function setTableNumber(int $tableNumber): void
    {
        $this->tableNumber = $tableNumber;
    }

    /**
     * Get the stats for a specific participant in this match.
     *
     * @return array<string, mixed>|null
     */
    public function getStatsForParticipant(string $participantId): ?array
    {
        if ($this->player1Id === $participantId) {
            return $this->player1Stats;
        }

        if ($this->player2Id === $participantId) {
            return $this->player2Stats;
        }

        return null;
    }

    /**
     * Get the opponent's stats for a specific participant.
     *
     * @return array<string, mixed>|null
     */
    public function getOpponentStatsForParticipant(string $participantId): ?array
    {
        if ($this->player1Id === $participantId) {
            return $this->player2Stats;
        }

        if ($this->player2Id === $participantId) {
            return $this->player1Stats;
        }

        return null;
    }
}
