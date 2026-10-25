<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Services\TiebreakerCalculatorInterface;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerContext;
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
        ?TiebreakerContext $context = null,
    ): array {
        if ($tiebreakerConfig === []) {
            return [];
        }

        $context ??= new TiebreakerContext;

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
                $context,
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
        TiebreakerContext $context,
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
                $participantMatches,
                $context
            ),
            TiebreakerType::OpponentOpponentWinPercentage => $this->calculateOpponentOpponentWinPercentage(
                $participantId,
                $allMatches,
                $definition->minValue
            ),
            TiebreakerType::GameWinPercentage => $this->calculateGameWinPercentage(
                $participantId,
                $participantMatches
            ),
            TiebreakerType::OpponentGameWinPercentage => $this->calculateOpponentGameWinPercentage(
                $participantId,
                $allMatches,
                $definition->minValue
            ),
            TiebreakerType::HeadToHead => $this->calculateHeadToHead(
                $participantId,
                $participantMatches,
                $standingsByParticipant,
                $context
            ),
            TiebreakerType::SonnebornBerger => $this->calculateSonnebornBerger(
                $participantId,
                $participantMatches,
                $standingsByParticipant
            ),
            TiebreakerType::Random => $this->calculateRandom($participantId, $context),
            TiebreakerType::OpponentWinPercentage => $this->calculateOpponentWinPercentage(
                $participantId,
                $allMatches,
                $definition->minValue
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
     * Progressive: sum of the running point total after each round, in round order.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateProgressive(
        string $participantId,
        array $participantMatches,
        TiebreakerContext $context,
    ): float {
        $sortedMatches = array_values(array_filter(
            $participantMatches,
            static fn (TournamentMatch $match): bool => $match->result()->isCompleted(),
        ));
        usort($sortedMatches, static function (TournamentMatch $a, TournamentMatch $b) use ($context): int {
            $roundA = $context->roundNumber($a->roundId());
            $roundB = $context->roundNumber($b->roundId());

            return $roundA !== null && $roundB !== null
                ? $roundA <=> $roundB
                : strcmp($a->roundId(), $b->roundId());
        });

        $cumulativeSum = 0.0;
        $totalPoints = 0.0;

        foreach ($sortedMatches as $match) {
            $totalPoints += $this->getMatchPoints($participantId, $match, $context);
            $cumulativeSum += $totalPoints;
        }

        return $cumulativeSum;
    }

    /**
     * Points earned by a participant in a match: the tournament's when known, else 3 per win.
     */
    private function getMatchPoints(string $participantId, TournamentMatch $match, TiebreakerContext $context): float
    {
        $points = $context->pointsFor($match->id()->value, $participantId);
        if ($points !== null) {
            return $points;
        }

        $result = $match->result();

        if ($match->player1Id() === $participantId) {
            return $result->player1Points() * 3.0;
        }

        if ($match->player2Id() === $participantId) {
            return $result->player2Points() * 3.0;
        }

        return 0.0;
    }

    /**
     * Match win rate: match points over matches played, a win 1, a draw half, a bye a win.
     *
     * @param  array<TournamentMatch>  $allMatches
     */
    private function calculateMatchWinRate(string $participantId, array $allMatches): float
    {
        $points = 0.0;
        $played = 0;

        foreach ($this->getParticipantMatches($participantId, $allMatches) as $match) {
            if (! $match->result()->isCompleted()) {
                continue;
            }

            $played++;
            $points += $match->player1Id() === $participantId
                ? $match->result()->player1Points()
                : $match->result()->player2Points();
        }

        return $played > 0 ? $points / $played : 0.0;
    }

    /**
     * Opponents' opponents win percentage: average of the opponents' OWP, each floored.
     *
     * @param  array<TournamentMatch>  $allMatches
     */
    private function calculateOpponentOpponentWinPercentage(
        string $participantId,
        array $allMatches,
        ?float $floor,
    ): float {
        $values = [];
        foreach ($this->getPlayedOpponentIds($participantId, $allMatches) as $opponentId) {
            $values[] = $this->floor($this->calculateOpponentWinPercentage($opponentId, $allMatches, $floor), $floor);
        }

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Game win percentage: games won over games played. The match scores are the games
     * (2-1); a match without scores counts as one game (a draw as half a game won) and a bye
     * as one game won.
     *
     * @param  array<TournamentMatch>  $participantMatches
     */
    private function calculateGameWinPercentage(string $participantId, array $participantMatches): float
    {
        $won = 0.0;
        $played = 0.0;

        foreach ($participantMatches as $match) {
            if (! $match->result()->isCompleted()) {
                continue;
            }

            $isPlayer1 = $match->player1Id() === $participantId;
            $own = $isPlayer1 ? $match->player1Score() : $match->player2Score();
            $opponent = $isPlayer1 ? $match->player2Score() : $match->player1Score();

            if (! $match->isBye() && $own !== null && $opponent !== null && $own + $opponent > 0) {
                $won += $own;
                $played += $own + $opponent;

                continue;
            }

            $won += $isPlayer1 ? $match->result()->player1Points() : $match->result()->player2Points();
            $played += 1.0;
        }

        return $played > 0.0 ? $won / $played : 0.0;
    }

    /**
     * Opponents' game win percentage: average of the opponents' GWP, each floored.
     *
     * @param  array<TournamentMatch>  $allMatches
     */
    private function calculateOpponentGameWinPercentage(string $participantId, array $allMatches, ?float $floor): float
    {
        $values = [];
        foreach ($this->getPlayedOpponentIds($participantId, $allMatches) as $opponentId) {
            $values[] = $this->floor(
                $this->calculateGameWinPercentage($opponentId, $this->getParticipantMatches($opponentId, $allMatches)),
                $floor,
            );
        }

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private function floor(float $value, ?float $floor): float
    {
        return $floor !== null && $value < $floor ? $floor : $value;
    }

    /**
     * Head-to-head: points earned in the matches against the players tied on points.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateHeadToHead(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
        TiebreakerContext $context,
    ): float {
        $own = $standingsByParticipant[$participantId] ?? null;
        if ($own === null) {
            return 0.0;
        }

        $points = 0.0;
        foreach ($participantMatches as $match) {
            if ($match->isBye() || ! $match->result()->isCompleted()) {
                continue;
            }

            $opponent = $standingsByParticipant[$this->getOpponentId($participantId, $match) ?? ''] ?? null;
            if ($opponent !== null && $opponent->points() === $own->points()) {
                $points += $this->getMatchPoints($participantId, $match, $context);
            }
        }

        return $points;
    }

    /**
     * Sonneborn-Berger: points of the opponents beaten plus half the points of those drawn.
     *
     * @param  array<TournamentMatch>  $participantMatches
     * @param  array<string, Standing>  $standingsByParticipant
     */
    private function calculateSonnebornBerger(
        string $participantId,
        array $participantMatches,
        array $standingsByParticipant,
    ): float {
        $sum = 0.0;
        foreach ($participantMatches as $match) {
            if ($match->isBye()) {
                continue;
            }

            $opponent = $standingsByParticipant[$this->getOpponentId($participantId, $match) ?? ''] ?? null;
            if ($opponent === null) {
                continue;
            }

            $share = $match->player1Id() === $participantId
                ? $match->result()->player1Points()
                : $match->result()->player2Points();
            if ($match->result() === MatchResult::DoubleLoss) {
                $share = 0.0;
            }

            $sum += $share * $opponent->points();
        }

        return $sum;
    }

    /**
     * Random: a value in [0, 1) fixed by the tournament seed, so the order holds across recalculations.
     */
    private function calculateRandom(string $participantId, TiebreakerContext $context): float
    {
        $hash = hash('sha256', $context->randomSeed.'|'.$participantId);

        return hexdec(substr($hash, 0, 13)) / 16 ** 13;
    }

    /**
     * Opponents met in completed matches (byes excluded).
     *
     * @param  array<TournamentMatch>  $allMatches
     * @return array<string>
     */
    private function getPlayedOpponentIds(string $participantId, array $allMatches): array
    {
        $opponents = [];
        foreach ($this->getParticipantMatches($participantId, $allMatches) as $match) {
            if ($match->isBye() || ! $match->result()->isCompleted()) {
                continue;
            }

            $opponentId = $this->getOpponentId($participantId, $match);
            if ($opponentId !== null) {
                $opponents[] = $opponentId;
            }
        }

        return $opponents;
    }

    /**
     * Opponent win percentage: average of the opponents' match win rates, each floored.
     *
     * @param  array<TournamentMatch>  $allMatches
     */
    private function calculateOpponentWinPercentage(
        string $participantId,
        array $allMatches,
        ?float $floor,
    ): float {
        $rates = [];
        foreach ($this->getPlayedOpponentIds($participantId, $allMatches) as $opponentId) {
            $rates[] = $this->floor($this->calculateMatchWinRate($opponentId, $allMatches), $floor);
        }

        return $rates === [] ? 0.0 : array_sum($rates) / count($rates);
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

        if ($opponentPoints === []) {
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

        if ($values === []) {
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
