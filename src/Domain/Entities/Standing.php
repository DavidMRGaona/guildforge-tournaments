<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;

final class Standing
{
    /**
     * @param  array<string, float>  $accumulatedStats
     * @param  array<string, float>  $calculatedTiebreakers
     */
    public function __construct(
        private readonly string $id,
        private readonly string $tournamentId,
        private readonly string $participantId,
        private int $rank,
        private int $matchesPlayed,
        private int $wins,
        private int $draws,
        private int $losses,
        private int $byes,
        private float $points,
        private float $buchholz = 0.0,
        private float $medianBuchholz = 0.0,
        private float $progressive = 0.0,
        private float $opponentWinPercentage = 0.0,
        private array $accumulatedStats = [],
        private array $calculatedTiebreakers = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tournamentId(): string
    {
        return $this->tournamentId;
    }

    public function participantId(): string
    {
        return $this->participantId;
    }

    public function rank(): int
    {
        return $this->rank;
    }

    public function matchesPlayed(): int
    {
        return $this->matchesPlayed;
    }

    public function wins(): int
    {
        return $this->wins;
    }

    public function draws(): int
    {
        return $this->draws;
    }

    public function losses(): int
    {
        return $this->losses;
    }

    public function byes(): int
    {
        return $this->byes;
    }

    public function points(): float
    {
        return $this->points;
    }

    public function buchholz(): float
    {
        return $this->buchholz;
    }

    public function medianBuchholz(): float
    {
        return $this->medianBuchholz;
    }

    public function progressive(): float
    {
        return $this->progressive;
    }

    public function opponentWinPercentage(): float
    {
        return $this->opponentWinPercentage;
    }

    /**
     * Get accumulated stats (custom stats summed across matches).
     *
     * @return array<string, float>
     */
    public function accumulatedStats(): array
    {
        return $this->accumulatedStats;
    }

    /**
     * Get a specific accumulated stat value.
     */
    public function getAccumulatedStat(string $key): float
    {
        return $this->accumulatedStats[$key] ?? 0.0;
    }

    /**
     * Get all calculated tiebreaker values.
     *
     * @return array<string, float>
     */
    public function calculatedTiebreakers(): array
    {
        return $this->calculatedTiebreakers;
    }

    /**
     * Get a specific calculated tiebreaker value.
     */
    public function getTiebreaker(string $key): float
    {
        return $this->calculatedTiebreakers[$key] ?? 0.0;
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
     * Update the rank.
     */
    public function updateRank(int $rank): void
    {
        $this->rank = $rank;
    }

    /**
     * Update all tiebreaker values.
     */
    public function updateTiebreakers(
        float $buchholz,
        float $medianBuchholz,
        float $progressive,
        float $opponentWinPercentage,
    ): void {
        $this->buchholz = $buchholz;
        $this->medianBuchholz = $medianBuchholz;
        $this->progressive = $progressive;
        $this->opponentWinPercentage = $opponentWinPercentage;
    }

    /**
     * Update accumulated stats.
     *
     * @param  array<string, float>  $stats
     */
    public function updateAccumulatedStats(array $stats): void
    {
        $this->accumulatedStats = $stats;
    }

    /**
     * Add to an accumulated stat value.
     */
    public function addToAccumulatedStat(string $key, float $value): void
    {
        if (! isset($this->accumulatedStats[$key])) {
            $this->accumulatedStats[$key] = 0.0;
        }
        $this->accumulatedStats[$key] += $value;
    }

    /**
     * Update calculated tiebreakers (extended tiebreakers beyond the core 4).
     *
     * @param  array<string, float>  $tiebreakers
     */
    public function updateCalculatedTiebreakers(array $tiebreakers): void
    {
        $this->calculatedTiebreakers = $tiebreakers;
    }

    /**
     * Set a specific calculated tiebreaker value.
     */
    public function setCalculatedTiebreaker(string $key, float $value): void
    {
        $this->calculatedTiebreakers[$key] = $value;
    }

    /**
     * Record a win.
     */
    public function recordWin(float $points): void
    {
        $this->matchesPlayed++;
        $this->wins++;
        $this->points += $points;
    }

    /**
     * Record a draw.
     */
    public function recordDraw(float $points): void
    {
        $this->matchesPlayed++;
        $this->draws++;
        $this->points += $points;
    }

    /**
     * Record a loss.
     */
    public function recordLoss(float $points): void
    {
        $this->matchesPlayed++;
        $this->losses++;
        $this->points += $points;
    }

    /**
     * Record a bye.
     */
    public function recordBye(float $points): void
    {
        $this->matchesPlayed++;
        $this->byes++;
        $this->points += $points;
    }
}
