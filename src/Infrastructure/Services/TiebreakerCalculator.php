<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Services\TiebreakerCalculatorInterface;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;

final class TiebreakerCalculator implements TiebreakerCalculatorInterface
{
    /**
     * @param  array<TournamentMatch>  $allMatches
     * @param  array<Standing>  $allStandings
     * @param  array<TiebreakerDefinition>  $tiebreakerConfig
     * @return array<string, float>
     */
    public function calculate(
        string $participantId,
        array $allMatches,
        array $allStandings,
        array $tiebreakerConfig,
    ): array {
        if (empty($tiebreakerConfig)) {
            return [];
        }

        $result = [];

        // Index standings by participant ID for quick lookup
        $standingsByParticipant = $this->indexStandingsByParticipant($allStandings);

        // Get matches involving this participant
        $participantMatches = $this->getParticipantMatches($participantId, $allMatches);

        foreach ($tiebreakerConfig as $definition) {
            $value = $this->calculateTiebreaker(
                $definition,
                $participantId,
                $participantMatches,
                $allMatches,
                $standingsByParticipant,
            );

            // Apply minValue floor if configured
            if ($definition->minValue !== null && $value < $definition->minValue) {
                $value = $definition->minValue;
            }

            $result[$definition->key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<TournamentMatch>  $allMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateTiebreaker(
        TiebreakerDefinition $definition,
        string $participantId,
        array $participantMatches,
        array $allMatches,
        array $standingsByParticipant,
    ): float {
        return match ($definition->type) {
            TiebreakerType::Buchholz => $this->calculateBuchholz(
                $participantId,
                $participantMatches,
                $standingsByParticipant
            ),
            TiebreakerType::MedianBuchholz => $this->calculateMedianBuchholz(
                $participantId,
                $participantMatches,
                $standingsByParticipant
            ),
            TiebreakerType::Progressive => $this->calculateProgressive(
                $participantId,
                $participantMatches
            ),
            TiebreakerType::OpponentWinPercentage => $this->calculateOpponentWinPercentage(
                $participantId,
                $participantMatches,
                $standingsByParticipant
            ),
            TiebreakerType::StrengthOfSchedule => $this->calculateStrengthOfSchedule(
                $participantId,
                $participantMatches,
                $standingsByParticipant
            ),
            TiebreakerType::MarginOfVictory => $this->calculateMarginOfVictory(
                $participantId,
                $participantMatches
            ),
            TiebreakerType::StatSum => $this->calculateStatSum(
                $participantId,
                $participantMatches,
                $definition->stat ?? ''
            ),
            TiebreakerType::StatDiff => $this->calculateStatDiff(
                $participantId,
                $participantMatches,
                $definition->stat ?? ''
            ),
            TiebreakerType::StatAverage => $this->calculateStatAverage(
                $participantId,
                $participantMatches,
                $definition->stat ?? ''
            ),
            TiebreakerType::StatMax => $this->calculateStatMax(
                $participantId,
                $participantMatches,
                $definition->stat ?? ''
            ),
            default => 0.0,
        };
    }

    /**
     * Buchholz: Sum of opponent points.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateBuchholz(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
    ): float {
        $sum = 0.0;

        foreach ($participantMatches as $match) {
            // Skip bye matches
            if ($match->isBye()) {
                continue;
            }

            $opponentId = $this->getOpponentId($participantId, $match);
            if ($opponentId === null) {
                continue;
            }

            $opponentStanding = $standingsByParticipant[$opponentId] ?? null;
            if ($opponentStanding !== null) {
                $sum += $opponentStanding->points();
            }
        }

        return $sum;
    }

    /**
     * Median Buchholz: Buchholz excluding best and worst opponent.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateMedianBuchholz(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
    ): float {
        $opponentPoints = [];

        foreach ($participantMatches as $match) {
            // Skip bye matches
            if ($match->isBye()) {
                continue;
            }

            $opponentId = $this->getOpponentId($participantId, $match);
            if ($opponentId === null) {
                continue;
            }

            $opponentStanding = $standingsByParticipant[$opponentId] ?? null;
            if ($opponentStanding !== null) {
                $opponentPoints[] = $opponentStanding->points();
            }
        }

        // If less than 3 opponents, can't remove best/worst
        if (count($opponentPoints) < 3) {
            return array_sum($opponentPoints);
        }

        // Sort and remove best and worst
        sort($opponentPoints);
        array_shift($opponentPoints); // Remove lowest
        array_pop($opponentPoints);   // Remove highest

        return array_sum($opponentPoints);
    }

    /**
     * Progressive: Cumulative points per round.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateProgressive(
        string $participantId,
        array $participantMatches,
    ): float {
        // Sort matches by round ID to ensure chronological order
        $sortedMatches = $participantMatches;
        usort($sortedMatches, fn (TournamentMatch $a, TournamentMatch $b) => strcmp($a->roundId(), $b->roundId()));

        $cumulativeSum = 0.0;
        $totalPoints = 0.0;

        foreach ($sortedMatches as $match) {
            // Calculate points earned in this match
            $matchPoints = $this->getMatchPoints($participantId, $match);
            $totalPoints += $matchPoints;
            $cumulativeSum += $totalPoints;
        }

        return $cumulativeSum;
    }

    /**
     * Get points earned by participant in a match.
     */
    private function getMatchPoints(string $participantId, TournamentMatch $match): float
    {
        $result = $match->result();

        if ($match->player1Id() === $participantId) {
            return $result->player1Points() * 3.0; // Assuming 3 points for win, 1.5 for draw
        }

        if ($match->player2Id() === $participantId) {
            return $result->player2Points() * 3.0;
        }

        return 0.0;
    }

    /**
     * Opponent Win Percentage: Average opponent win rate.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateOpponentWinPercentage(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
    ): float {
        $winPercentages = [];

        foreach ($participantMatches as $match) {
            // Skip bye matches
            if ($match->isBye()) {
                continue;
            }

            $opponentId = $this->getOpponentId($participantId, $match);
            if ($opponentId === null) {
                continue;
            }

            $opponentStanding = $standingsByParticipant[$opponentId] ?? null;
            if ($opponentStanding !== null && $opponentStanding->matchesPlayed() > 0) {
                $winPercentage = (float) $opponentStanding->wins() / $opponentStanding->matchesPlayed();
                $winPercentages[] = $winPercentage;
            }
        }

        if (empty($winPercentages)) {
            return 0.0;
        }

        return array_sum($winPercentages) / count($winPercentages);
    }

    /**
     * Strength of Schedule: Average opponent points.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateStrengthOfSchedule(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
    ): float {
        $opponentPoints = [];

        foreach ($participantMatches as $match) {
            // Skip bye matches
            if ($match->isBye()) {
                continue;
            }

            $opponentId = $this->getOpponentId($participantId, $match);
            if ($opponentId === null) {
                continue;
            }

            $opponentStanding = $standingsByParticipant[$opponentId] ?? null;
            if ($opponentStanding !== null) {
                $opponentPoints[] = $opponentStanding->points();
            }
        }

        if (empty($opponentPoints)) {
            return 0.0;
        }

        return array_sum($opponentPoints) / count($opponentPoints);
    }

    /**
     * Margin of Victory: Sum of positive point margins.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateMarginOfVictory(
        string $participantId,
        array $participantMatches,
    ): float {
        $totalMargin = 0.0;

        foreach ($participantMatches as $match) {
            // Skip bye matches and matches without scores
            if ($match->isBye()) {
                continue;
            }

            $player1Score = $match->player1Score();
            $player2Score = $match->player2Score();

            if ($player1Score === null || $player2Score === null) {
                continue;
            }

            // Calculate margin from participant's perspective
            if ($match->player1Id() === $participantId) {
                $margin = $player1Score - $player2Score;
            } elseif ($match->player2Id() === $participantId) {
                $margin = $player2Score - $player1Score;
            } else {
                continue;
            }

            // Only add positive margins
            if ($margin > 0) {
                $totalMargin += $margin;
            }
        }

        return $totalMargin;
    }

    /**
     * Stat Sum: Total of a stat across matches.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateStatSum(
        string $participantId,
        array $participantMatches,
        string $stat,
    ): float {
        $sum = 0.0;

        foreach ($participantMatches as $match) {
            $stats = $match->getStatsForParticipant($participantId);
            if ($stats !== null && isset($stats[$stat])) {
                $sum += (float) $stats[$stat];
            }
        }

        return $sum;
    }

    /**
     * Stat Diff: Sum of (own stat - opponent stat).
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateStatDiff(
        string $participantId,
        array $participantMatches,
        string $stat,
    ): float {
        $totalDiff = 0.0;

        foreach ($participantMatches as $match) {
            $ownStats = $match->getStatsForParticipant($participantId);
            $opponentStats = $match->getOpponentStatsForParticipant($participantId);

            $ownValue = ($ownStats !== null && isset($ownStats[$stat])) ? (float) $ownStats[$stat] : 0.0;
            $opponentValue = ($opponentStats !== null && isset($opponentStats[$stat])) ? (float) $opponentStats[$stat] : 0.0;

            $totalDiff += $ownValue - $opponentValue;
        }

        return $totalDiff;
    }

    /**
     * Stat Average: Mean of a stat.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateStatAverage(
        string $participantId,
        array $participantMatches,
        string $stat,
    ): float {
        $values = [];

        foreach ($participantMatches as $match) {
            $stats = $match->getStatsForParticipant($participantId);
            if ($stats !== null && isset($stats[$stat])) {
                $values[] = (float) $stats[$stat];
            }
        }

        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Stat Max: Best single-match value.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateStatMax(
        string $participantId,
        array $participantMatches,
        string $stat,
    ): float {
        $max = 0.0;

        foreach ($participantMatches as $match) {
            $stats = $match->getStatsForParticipant($participantId);
            if ($stats !== null && isset($stats[$stat])) {
                $value = (float) $stats[$stat];
                if ($value > $max) {
                    $max = $value;
                }
            }
        }

        return $max;
    }

    /**
     * Index standings by participant ID.
     *
     * @param  array<Standing>  $standings
     * @return array<string, Standing>
     */
    private function indexStandingsByParticipant(array $standings): array
    {
        $indexed = [];
        foreach ($standings as $standing) {
            $indexed[$standing->participantId()] = $standing;
        }

        return $indexed;
    }

    /**
     * Get matches involving a participant.
     *
     * @param  array<TournamentMatch>  $allMatches
     * @return array<TournamentMatch>
     */
    private function getParticipantMatches(string $participantId, array $allMatches): array
    {
        return array_filter(
            $allMatches,
            fn (TournamentMatch $match) => $match->involvesParticipant($participantId)
        );
    }

    /**
     * Get opponent ID from a match.
     */
    private function getOpponentId(string $participantId, TournamentMatch $match): ?string
    {
        if ($match->player1Id() === $participantId) {
            return $match->player2Id();
        }

        if ($match->player2Id() === $participantId) {
            return $match->player1Id();
        }

        return null;
    }
}
