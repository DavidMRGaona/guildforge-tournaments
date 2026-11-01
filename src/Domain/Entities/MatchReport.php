<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * A result a player reported that still waits for the opponent (or the organization) to confirm
 * it. The match keeps no official result until then.
 */
final class MatchReport
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function __construct(
        private readonly string $matchId,
        private MatchResult $result,
        private readonly string $reportedById,
        private DateTimeImmutable $reportedAt,
        private ?int $player1Score = null,
        private ?int $player2Score = null,
        private ?array $player1Stats = null,
        private ?array $player2Stats = null,
        private ?string $disputedById = null,
        private ?string $disputeReason = null,
        private ?DateTimeImmutable $disputedAt = null,
    ) {}

    public function matchId(): string
    {
        return $this->matchId;
    }

    public function result(): MatchResult
    {
        return $this->result;
    }

    public function reportedById(): string
    {
        return $this->reportedById;
    }

    public function reportedAt(): DateTimeImmutable
    {
        return $this->reportedAt;
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

    public function disputedById(): ?string
    {
        return $this->disputedById;
    }

    public function disputeReason(): ?string
    {
        return $this->disputeReason;
    }

    public function disputedAt(): ?DateTimeImmutable
    {
        return $this->disputedAt;
    }

    public function isDisputed(): bool
    {
        return $this->disputedAt instanceof DateTimeImmutable;
    }

    /**
     * Identifies what the report says, so the opponent confirms the version they saw and never a
     * correction made meanwhile.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->result->value,
            $this->player1Score,
            $this->player2Score,
            $this->sorted($this->player1Stats),
            $this->sorted($this->player2Stats),
            $this->reportedAt->format('U'),
        ]));
    }

    /**
     * The reporter changes their report: it waits for confirmation again, without the dispute.
     *
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function correct(
        MatchResult $result,
        ?int $player1Score,
        ?int $player2Score,
        ?array $player1Stats,
        ?array $player2Stats,
        DateTimeImmutable $at,
    ): void {
        $this->result = $result;
        $this->player1Score = $player1Score;
        $this->player2Score = $player2Score;
        $this->player1Stats = $player1Stats;
        $this->player2Stats = $player2Stats;
        $this->reportedAt = $at;
        $this->disputedById = null;
        $this->disputeReason = null;
        $this->disputedAt = null;
    }

    /**
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>|null
     */
    private function sorted(?array $stats): ?array
    {
        if ($stats !== null) {
            ksort($stats);
        }

        return $stats;
    }

    public function dispute(string $byUserId, string $reason, DateTimeImmutable $at): void
    {
        $this->disputedById = $byUserId;
        $this->disputeReason = $reason;
        $this->disputedAt = $at;
    }
}
