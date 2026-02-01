<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;

interface StandingCalculatorServiceInterface
{
    /**
     * Calculate standings for all participants based on match results.
     *
     * @param  array<Participant>  $participants  All tournament participants
     * @param  array<TournamentMatch>  $matches  All completed matches
     * @param  array<ScoreWeight>  $scoreWeights  Scoring configuration
     * @param  array<Tiebreaker>  $tiebreakers  Tiebreaker criteria in order of priority
     * @return array<Standing>  Calculated standings sorted by rank
     */
    public function calculate(
        array $participants,
        array $matches,
        array $scoreWeights,
        array $tiebreakers,
    ): array;

    /**
     * Calculate Buchholz score for a participant.
     * Sum of opponents' points.
     *
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateBuchholz(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float;

    /**
     * Calculate Median Buchholz score for a participant.
     * Buchholz excluding best and worst opponent scores.
     *
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateMedianBuchholz(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float;

    /**
     * Calculate Progressive score for a participant.
     * Running total of points after each round.
     *
     * @param  array<TournamentMatch>  $matches
     * @param  array<ScoreWeight>  $scoreWeights
     */
    public function calculateProgressive(
        string $participantId,
        array $matches,
        array $scoreWeights,
    ): float;

    /**
     * Calculate Opponent Win Percentage for a participant.
     * Average win percentage of all opponents.
     *
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateOpponentWinPercentage(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float;
}
