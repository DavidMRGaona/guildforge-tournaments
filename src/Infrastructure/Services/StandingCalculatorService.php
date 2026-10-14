<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\Services\ScoringRuleEvaluatorInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Services\StandingCalculatorServiceInterface;
use Modules\Tournaments\Domain\Services\TiebreakerCalculatorInterface;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Ramsey\Uuid\Uuid;

final readonly class StandingCalculatorService implements StandingCalculatorServiceInterface
{
    public function __construct(
        private ScoringRuleEvaluatorInterface $scoringRuleEvaluator = new ScoringRuleEvaluator(),
        private TiebreakerCalculatorInterface $tiebreakerCalculator = new TiebreakerCalculator(),
    ) {
    }

    /**
     * @param  array<Participant>  $participants
     * @param  array<TournamentMatch>  $matches
     * @param  array<ScoringRule>  $scoringRules
     * @param  array<TiebreakerDefinition>  $tiebreakerConfig
     * @return array<Standing>
     */
    public function calculateWithRules(
        array $participants,
        array $matches,
        array $scoringRules,
        array $tiebreakerConfig,
    ): array {
        if ($participants === []) {
            return [];
        }

        $tournamentId = $participants[0]->tournamentId();

        /** @var array<string, Standing> $standings */
        $standings = [];
        foreach ($participants as $participant) {
            $standings[$participant->id()->value] = new Standing(
                id: Uuid::uuid4()->toString(),
                tournamentId: $tournamentId,
                participantId: $participant->id()->value,
                rank: 0,
                matchesPlayed: 0,
                wins: 0,
                draws: 0,
                losses: 0,
                byes: 0,
                points: 0.0,
            );
        }

        $playedMatches = array_values(array_filter(
            $matches,
            static fn (TournamentMatch $match): bool => $match->result() !== MatchResult::NotPlayed,
        ));

        foreach ($playedMatches as $match) {
            $this->recordMatchWithRules($match, $standings, $scoringRules);
        }

        $standingsArray = array_values($standings);

        // The standings table always shows these four; the configured ones decide the order
        $displayed = [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
            new TiebreakerDefinition('median_buchholz', 'Median Buchholz', TiebreakerType::MedianBuchholz),
            new TiebreakerDefinition('progressive', 'Progressive', TiebreakerType::Progressive),
            new TiebreakerDefinition('owp', 'OWP', TiebreakerType::OpponentWinPercentage),
        ];

        foreach ($standingsArray as $standing) {
            $participantId = $standing->participantId();
            $shown = $this->tiebreakerCalculator->calculate($participantId, $playedMatches, $standingsArray, $displayed);
            $configured = $this->tiebreakerCalculator->calculate($participantId, $playedMatches, $standingsArray, $tiebreakerConfig);

            $standing->updateTiebreakers(
                buchholz: $shown['buchholz'] ?? 0.0,
                medianBuchholz: $shown['median_buchholz'] ?? 0.0,
                progressive: $shown['progressive'] ?? 0.0,
                opponentWinPercentage: $shown['owp'] ?? 0.0,
            );
            $standing->updateCalculatedTiebreakers($configured);
        }

        usort($standingsArray, static function (Standing $a, Standing $b) use ($tiebreakerConfig): int {
            $pointsComparison = $b->points() <=> $a->points();
            if ($pointsComparison !== 0) {
                return $pointsComparison;
            }

            foreach ($tiebreakerConfig as $definition) {
                $valueA = $a->getTiebreaker($definition->key);
                $valueB = $b->getTiebreaker($definition->key);
                $comparison = $definition->direction === SortDirection::Ascending
                    ? $valueA <=> $valueB
                    : $valueB <=> $valueA;

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        foreach ($standingsArray as $index => $standing) {
            $standing->updateRank($index + 1);
        }

        return $standingsArray;
    }

    /**
     * @param  array<Participant>  $participants
     * @param  array<TournamentMatch>  $matches
     * @param  array<ScoreWeight>  $scoreWeights
     * @param  array<Tiebreaker>  $tiebreakers
     * @return array<Standing>
     */
    public function calculate(
        array $participants,
        array $matches,
        array $scoreWeights,
        array $tiebreakers,
    ): array {
        if (count($participants) === 0) {
            return [];
        }

        $tournamentId = $participants[0]->tournamentId();

        // Build score weight map for quick lookup
        $scoreWeightMap = $this->buildScoreWeightMap($scoreWeights);

        // Initialize standings for all participants
        $standings = [];
        foreach ($participants as $participant) {
            $standings[$participant->id()->value] = new Standing(
                id: Uuid::uuid4()->toString(),
                tournamentId: $tournamentId,
                participantId: $participant->id()->value,
                rank: 0,
                matchesPlayed: 0,
                wins: 0,
                draws: 0,
                losses: 0,
                byes: 0,
                points: 0.0,
            );
        }

        // Process all completed matches
        foreach ($matches as $match) {
            $this->processMatch($match, $standings, $scoreWeightMap);
        }

        // Calculate tiebreakers
        $standingsArray = array_values($standings);
        foreach ($standingsArray as $standing) {
            $buchholz = in_array(Tiebreaker::Buchholz, $tiebreakers, true) || in_array(Tiebreaker::MedianBuchholz, $tiebreakers, true)
                ? $this->calculateBuchholz($standing->participantId(), $matches, $standingsArray)
                : 0.0;

            $medianBuchholz = in_array(Tiebreaker::MedianBuchholz, $tiebreakers, true)
                ? $this->calculateMedianBuchholz($standing->participantId(), $matches, $standingsArray)
                : 0.0;

            $progressive = in_array(Tiebreaker::Progressive, $tiebreakers, true)
                ? $this->calculateProgressive($standing->participantId(), $matches, $scoreWeights)
                : 0.0;

            $opponentWinPercentage = in_array(Tiebreaker::OpponentWinPercentage, $tiebreakers, true)
                ? $this->calculateOpponentWinPercentage($standing->participantId(), $matches, $standingsArray)
                : 0.0;

            $standing->updateTiebreakers(
                buchholz: $buchholz,
                medianBuchholz: $medianBuchholz,
                progressive: $progressive,
                opponentWinPercentage: $opponentWinPercentage,
            );
        }

        // Sort standings by points, then by tiebreakers
        usort($standingsArray, fn (Standing $a, Standing $b): int => $this->compareStandings($a, $b, $tiebreakers));

        // Assign ranks
        foreach ($standingsArray as $index => $standing) {
            $standing->updateRank($index + 1);
        }

        return $standingsArray;
    }

    /**
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateBuchholz(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float {
        $opponentIds = $this->getOpponentIds($participantId, $matches);
        $standingMap = $this->buildStandingMap($allStandings);

        $buchholz = 0.0;
        foreach ($opponentIds as $opponentId) {
            if (isset($standingMap[$opponentId])) {
                $buchholz += $standingMap[$opponentId]->points();
            }
        }

        return $buchholz;
    }

    /**
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateMedianBuchholz(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float {
        $opponentIds = $this->getOpponentIds($participantId, $matches);
        $standingMap = $this->buildStandingMap($allStandings);

        $opponentScores = [];
        foreach ($opponentIds as $opponentId) {
            if (isset($standingMap[$opponentId])) {
                $opponentScores[] = $standingMap[$opponentId]->points();
            }
        }

        // Need at least 3 opponents to calculate median
        if (count($opponentScores) < 3) {
            return $this->calculateBuchholz($participantId, $matches, $allStandings);
        }

        // Remove best and worst
        sort($opponentScores);
        array_shift($opponentScores); // Remove lowest
        array_pop($opponentScores);   // Remove highest

        return array_sum($opponentScores);
    }

    /**
     * @param  array<TournamentMatch>  $matches
     * @param  array<ScoreWeight>  $scoreWeights
     */
    public function calculateProgressive(
        string $participantId,
        array $matches,
        array $scoreWeights,
    ): float {
        $scoreWeightMap = $this->buildScoreWeightMap($scoreWeights);

        // Group matches by round and sort by round ID
        $matchesByRound = [];
        foreach ($matches as $match) {
            if ($match->involvesParticipant($participantId) && $match->result()->isCompleted()) {
                $matchesByRound[$match->roundId()][] = $match;
            }
        }

        // Sort round IDs (assuming format like "round-1", "round-2" or sortable strings)
        ksort($matchesByRound, SORT_NATURAL);

        // Calculate progressive score (running total after each round)
        $runningTotal = 0.0;
        $progressive = 0.0;

        foreach ($matchesByRound as $roundMatches) {
            foreach ($roundMatches as $match) {
                $points = $this->getPointsForParticipant($participantId, $match, $scoreWeightMap);
                $runningTotal += $points;
            }
            $progressive += $runningTotal;
        }

        return $progressive;
    }

    /**
     * @param  array<TournamentMatch>  $matches
     * @param  array<Standing>  $allStandings
     */
    public function calculateOpponentWinPercentage(
        string $participantId,
        array $matches,
        array $allStandings,
    ): float {
        $opponentIds = $this->getOpponentIds($participantId, $matches);
        $standingMap = $this->buildStandingMap($allStandings);

        if (count($opponentIds) === 0) {
            return 0.0;
        }

        $totalWinPercentage = 0.0;
        $validOpponents = 0;

        foreach ($opponentIds as $opponentId) {
            if (isset($standingMap[$opponentId])) {
                $standing = $standingMap[$opponentId];
                if ($standing->matchesPlayed() > 0) {
                    $winPercentage = $standing->wins() / $standing->matchesPlayed();
                    $totalWinPercentage += $winPercentage;
                    $validOpponents++;
                }
            }
        }

        if ($validOpponents === 0) {
            return 0.0;
        }

        return $totalWinPercentage / $validOpponents;
    }

    /**
     * Build score weight map for quick lookup.
     *
     * @param  array<ScoreWeight>  $scoreWeights
     * @return array<string, float>
     */
    private function buildScoreWeightMap(array $scoreWeights): array
    {
        $map = [];
        foreach ($scoreWeights as $weight) {
            $map[$weight->key] = $weight->points;
        }

        return $map;
    }

    /**
     * Build standing map for quick lookup.
     *
     * @param  array<Standing>  $standings
     * @return array<string, Standing>
     */
    private function buildStandingMap(array $standings): array
    {
        $map = [];
        foreach ($standings as $standing) {
            $map[$standing->participantId()] = $standing;
        }

        return $map;
    }

    /**
     * Process a single match and update standings.
     *
     * @param  array<string, Standing>  $standings
     * @param  array<string, float>  $scoreWeightMap
     */
    private function processMatch(TournamentMatch $match, array &$standings, array $scoreWeightMap): void
    {
        $result = $match->result();

        // Skip unplayed matches
        if ($result === MatchResult::NotPlayed) {
            return;
        }

        $player1Id = $match->player1Id();
        $player2Id = $match->player2Id();

        // Handle bye
        if ($result === MatchResult::Bye) {
            if (isset($standings[$player1Id])) {
                $byePoints = $scoreWeightMap['bye'] ?? 0.0;
                $standings[$player1Id]->recordBye($byePoints);
            }

            return;
        }

        // Handle double loss
        if ($result === MatchResult::DoubleLoss) {
            $lossPoints = $scoreWeightMap['loss'] ?? 0.0;
            if (isset($standings[$player1Id])) {
                $standings[$player1Id]->recordLoss($lossPoints);
            }
            if ($player2Id !== null && isset($standings[$player2Id])) {
                $standings[$player2Id]->recordLoss($lossPoints);
            }

            return;
        }

        // Handle draw
        if ($result === MatchResult::Draw) {
            $drawPoints = $scoreWeightMap['draw'] ?? 0.0;
            if (isset($standings[$player1Id])) {
                $standings[$player1Id]->recordDraw($drawPoints);
            }
            if ($player2Id !== null && isset($standings[$player2Id])) {
                $standings[$player2Id]->recordDraw($drawPoints);
            }

            return;
        }

        // Handle wins/losses
        $winPoints = $scoreWeightMap['win'] ?? 0.0;
        $lossPoints = $scoreWeightMap['loss'] ?? 0.0;

        if ($result === MatchResult::PlayerOneWin) {
            if (isset($standings[$player1Id])) {
                $standings[$player1Id]->recordWin($winPoints);
            }
            if ($player2Id !== null && isset($standings[$player2Id])) {
                $standings[$player2Id]->recordLoss($lossPoints);
            }
        } elseif ($result === MatchResult::PlayerTwoWin) {
            if (isset($standings[$player1Id])) {
                $standings[$player1Id]->recordLoss($lossPoints);
            }
            if ($player2Id !== null && isset($standings[$player2Id])) {
                $standings[$player2Id]->recordWin($winPoints);
            }
        }
    }

    /**
     * Add a played match to the standings, with the points the scoring rules award.
     *
     * @param  array<string, Standing>  $standings
     * @param  array<ScoringRule>  $scoringRules
     */
    private function recordMatchWithRules(TournamentMatch $match, array $standings, array $scoringRules): void
    {
        $result = $match->result();
        $points = $this->scoringRuleEvaluator->evaluate(
            $result,
            $scoringRules,
            $match->player1Stats() ?? [],
            $match->player2Stats() ?? [],
        );

        $player1 = $standings[$match->player1Id()] ?? null;
        $player2Id = $match->player2Id();
        $player2 = $player2Id !== null ? ($standings[$player2Id] ?? null) : null;

        switch ($result) {
            case MatchResult::Bye:
                $player1?->recordBye($points['player1']);
                break;
            case MatchResult::Draw:
                $player1?->recordDraw($points['player1']);
                $player2?->recordDraw($points['player2']);
                break;
            case MatchResult::DoubleLoss:
                $player1?->recordLoss($points['player1']);
                $player2?->recordLoss($points['player2']);
                break;
            case MatchResult::PlayerOneWin:
                $player1?->recordWin($points['player1']);
                $player2?->recordLoss($points['player2']);
                break;
            case MatchResult::PlayerTwoWin:
                $player1?->recordLoss($points['player1']);
                $player2?->recordWin($points['player2']);
                break;
            case MatchResult::NotPlayed:
                break;
        }
    }

    /**
     * Get all opponent IDs for a participant.
     *
     * @param  array<TournamentMatch>  $matches
     * @return array<string>
     */
    private function getOpponentIds(string $participantId, array $matches): array
    {
        $opponentIds = [];

        foreach ($matches as $match) {
            if (! $match->involvesParticipant($participantId)) {
                continue;
            }

            if ($match->isBye()) {
                continue;
            }

            if (! $match->result()->isCompleted()) {
                continue;
            }

            if ($match->player1Id() === $participantId) {
                $opponentIds[] = $match->player2Id();
            } else {
                $opponentIds[] = $match->player1Id();
            }
        }

        return array_filter($opponentIds, fn (?string $id): bool => $id !== null);
    }

    /**
     * Get points for a specific participant in a match.
     *
     * @param  array<string, float>  $scoreWeightMap
     */
    private function getPointsForParticipant(string $participantId, TournamentMatch $match, array $scoreWeightMap): float
    {
        $result = $match->result();

        if ($result === MatchResult::Bye && $match->player1Id() === $participantId) {
            return $scoreWeightMap['bye'] ?? 0.0;
        }

        if ($result === MatchResult::Draw) {
            return $scoreWeightMap['draw'] ?? 0.0;
        }

        if ($result === MatchResult::DoubleLoss) {
            return $scoreWeightMap['loss'] ?? 0.0;
        }

        $isPlayer1 = $match->player1Id() === $participantId;

        if ($result === MatchResult::PlayerOneWin) {
            return $isPlayer1 ? ($scoreWeightMap['win'] ?? 0.0) : ($scoreWeightMap['loss'] ?? 0.0);
        }

        if ($result === MatchResult::PlayerTwoWin) {
            return $isPlayer1 ? ($scoreWeightMap['loss'] ?? 0.0) : ($scoreWeightMap['win'] ?? 0.0);
        }

        return 0.0;
    }

    /**
     * Compare two standings for sorting.
     *
     * @param  array<Tiebreaker>  $tiebreakers
     */
    private function compareStandings(Standing $a, Standing $b, array $tiebreakers): int
    {
        // First compare by points (descending)
        $pointsComparison = $b->points() <=> $a->points();
        if ($pointsComparison !== 0) {
            return $pointsComparison;
        }

        // Then compare by each tiebreaker in order
        foreach ($tiebreakers as $tiebreaker) {
            $comparison = match ($tiebreaker) {
                Tiebreaker::Buchholz => $b->buchholz() <=> $a->buchholz(),
                Tiebreaker::MedianBuchholz => $b->medianBuchholz() <=> $a->medianBuchholz(),
                Tiebreaker::Progressive => $b->progressive() <=> $a->progressive(),
                Tiebreaker::OpponentWinPercentage => $b->opponentWinPercentage() <=> $a->opponentWinPercentage(),
                Tiebreaker::HeadToHead => 0, // Head-to-head requires match lookup, skip for now
            };

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }
}
