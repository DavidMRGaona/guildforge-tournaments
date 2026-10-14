<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;

final readonly class SwissPairingService implements SwissPairingServiceInterface
{
    /**
     * Generate pairings for a new round using the Swiss pairing algorithm.
     *
     * @param  array<Participant>  $participants
     * @param  array<Standing>  $standings
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups
     * @return array<TournamentMatch>
     */
    public function generatePairings(
        array $participants,
        array $standings,
        array $previousMatchups,
        int $roundNumber,
        ?PairingConfig $config = null,
    ): array {
        if (count($participants) === 0) {
            return [];
        }

        $config ??= new PairingConfig();

        $sortedParticipants = $this->orderParticipants($participants, $standings, $roundNumber, $config);

        // Handle odd number of participants - assign bye first
        $byeMatch = null;
        if (count($sortedParticipants) % 2 !== 0) {
            $byeParticipant = $this->selectByeParticipant($sortedParticipants, $previousMatchups, $config);
            $byeMatch = $this->createByeMatch($byeParticipant);

            // Remove bye participant from the pool
            $sortedParticipants = array_values(array_filter(
                $sortedParticipants,
                fn (Participant $p): bool => $p->id()->value !== $byeParticipant->id()->value
            ));
        }

        $matches = $this->pairParticipants(
            $sortedParticipants,
            $config->avoidRematches ? $previousMatchups : [],
            $roundNumber,
        );

        // Add bye match at the end if exists
        if ($byeMatch !== null) {
            $matches[] = $byeMatch;
        }

        // Assign table numbers to regular matches
        $this->assignTableNumbers($matches);

        return $matches;
    }

    /**
     * Order participants for pairing: at random for the first round, the random method or random
     * sorting; otherwise by points (or the accumulated stat), ties kept in standings order.
     *
     * @param  array<Participant>  $participants
     * @param  array<Standing>  $standings
     * @return array<Participant>
     */
    private function orderParticipants(array $participants, array $standings, int $roundNumber, PairingConfig $config): array
    {
        if ($roundNumber === 1
            || count($standings) === 0
            || $config->method === PairingMethod::Random
            || $config->sortBy === PairingSortCriteria::Random) {
            $shuffled = array_values($participants);
            shuffle($shuffled);

            return $shuffled;
        }

        $scoreMap = [];
        $rankMap = [];
        $statMap = [];
        foreach ($standings as $standing) {
            $scoreMap[$standing->participantId()] = $standing->points();
            $rankMap[$standing->participantId()] = $standing->rank();
            $statMap[$standing->participantId()] = $config->sortByStat !== null
                ? $standing->getAccumulatedStat($config->sortByStat)
                : 0.0;
        }

        $byStat = $config->sortBy === PairingSortCriteria::Stat;

        usort($participants, function (Participant $a, Participant $b) use ($scoreMap, $rankMap, $statMap, $byStat): int {
            $idA = $a->id()->value;
            $idB = $b->id()->value;

            if ($byStat && ($statMap[$idA] ?? 0.0) !== ($statMap[$idB] ?? 0.0)) {
                return ($statMap[$idB] ?? 0.0) <=> ($statMap[$idA] ?? 0.0);
            }

            $scoreA = $scoreMap[$idA] ?? 0.0;
            $scoreB = $scoreMap[$idB] ?? 0.0;
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return ($rankMap[$idA] ?? PHP_INT_MAX) <=> ($rankMap[$idB] ?? PHP_INT_MAX);
        });

        return $participants;
    }

    /**
     * Select the participant who sits out: among those below the bye limit (everyone if nobody is),
     * the lowest ranked, the highest ranked or one at random, as configured.
     *
     * @param  array<Participant>  $participants  In pairing order
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups
     */
    private function selectByeParticipant(array $participants, array $previousMatchups, PairingConfig $config): Participant
    {
        $byes = [];
        foreach ($previousMatchups as $matchup) {
            if (($matchup['player2Id'] ?? null) === null) {
                $byes[$matchup['player1Id']] = ($byes[$matchup['player1Id']] ?? 0) + 1;
            }
        }

        $eligible = array_values(array_filter(
            $participants,
            static function (Participant $participant) use ($byes, $config): bool {
                $received = max($byes[$participant->id()->value] ?? 0, $participant->hasReceivedBye() ? 1 : 0);

                return $received < $config->maxByesPerPlayer;
            },
        ));

        if ($eligible === []) {
            $eligible = array_values($participants);
        }

        return match ($config->byeAssignment) {
            ByeAssignment::LowestRanked => $eligible[count($eligible) - 1],
            ByeAssignment::HighestRanked => $eligible[0],
            ByeAssignment::Random => $eligible[array_rand($eligible)],
        };
    }

    /**
     * Create a bye match for a participant.
     */
    private function createByeMatch(Participant $participant): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: '',  // Will be set by caller
            player1Id: $participant->id()->value,
            player2Id: null,
            result: MatchResult::Bye,
            tableNumber: null,
        );
    }

    /**
     * Pair participants using Swiss algorithm.
     *
     * @param  array<Participant>  $participants  Sorted by score descending
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups
     * @return array<TournamentMatch>
     */
    private function pairParticipants(array $participants, array $previousMatchups, int $roundNumber): array
    {
        $matches = [];
        $paired = [];

        // Convert previousMatchups to a more efficient lookup structure
        $matchupMap = $this->buildMatchupMap($previousMatchups);

        $count = count($participants);

        for ($i = 0; $i < $count; $i++) {
            // Skip if already paired
            if (isset($paired[$participants[$i]->id()->value])) {
                continue;
            }

            $player1 = $participants[$i];

            // Find best opponent (prefer similar score, avoid rematch)
            $bestOpponent = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $participants[$j];

                // Skip if already paired
                if (isset($paired[$candidate->id()->value])) {
                    continue;
                }

                // Check if they've played before
                if ($this->havePlayed($player1->id()->value, $candidate->id()->value, $matchupMap)) {
                    continue;
                }

                $bestOpponent = $candidate;
                break;
            }

            // If no valid opponent found (all already paired or all rematches), take first available
            if ($bestOpponent === null) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (! isset($paired[$participants[$j]->id()->value])) {
                        $bestOpponent = $participants[$j];
                        break;
                    }
                }
            }

            if ($bestOpponent !== null) {
                $matches[] = new TournamentMatch(
                    id: MatchId::generate(),
                    roundId: '',  // Will be set by caller
                    player1Id: $player1->id()->value,
                    player2Id: $bestOpponent->id()->value,
                    result: MatchResult::NotPlayed,
                );

                $paired[$player1->id()->value] = true;
                $paired[$bestOpponent->id()->value] = true;
            }
        }

        return $matches;
    }

    /**
     * Build efficient lookup map for previous matchups.
     *
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups
     * @return array<string, array<string, bool>>
     */
    private function buildMatchupMap(array $previousMatchups): array
    {
        $map = [];

        foreach ($previousMatchups as $matchup) {
            $p1 = $matchup['player1Id'];
            $p2 = $matchup['player2Id'] ?? null;

            if ($p2 === null) {
                continue; // Skip bye matches
            }

            if (! isset($map[$p1])) {
                $map[$p1] = [];
            }
            if (! isset($map[$p2])) {
                $map[$p2] = [];
            }

            $map[$p1][$p2] = true;
            $map[$p2][$p1] = true;
        }

        return $map;
    }

    /**
     * Check if two players have already played.
     *
     * @param  array<string, array<string, bool>>  $matchupMap
     */
    private function havePlayed(string $player1Id, string $player2Id, array $matchupMap): bool
    {
        return isset($matchupMap[$player1Id][$player2Id]);
    }

    /**
     * Assign table numbers to regular matches.
     *
     * @param  array<TournamentMatch>  $matches
     */
    private function assignTableNumbers(array &$matches): void
    {
        $tableNumber = 1;

        foreach ($matches as $match) {
            if (! $match->isBye()) {
                $match->setTableNumber($tableNumber);
                $tableNumber++;
            }
        }
    }
}
