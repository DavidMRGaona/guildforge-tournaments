<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;

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
    ): array {
        if (count($participants) === 0) {
            return [];
        }

        // Sort participants by standings (or randomly for round 1)
        $sortedParticipants = $this->sortParticipantsByScore($participants, $standings, $roundNumber);

        // Handle odd number of participants - assign bye first
        $byeMatch = null;
        if (count($sortedParticipants) % 2 !== 0) {
            $byeParticipant = $this->selectByeParticipant($sortedParticipants);
            $byeMatch = $this->createByeMatch($byeParticipant);

            // Remove bye participant from the pool
            $sortedParticipants = array_values(array_filter(
                $sortedParticipants,
                fn (Participant $p): bool => $p->id()->value !== $byeParticipant->id()->value
            ));
        }

        // Generate pairings using Swiss algorithm
        $matches = $this->pairParticipants($sortedParticipants, $previousMatchups, $roundNumber);

        // Add bye match at the end if exists
        if ($byeMatch !== null) {
            $matches[] = $byeMatch;
        }

        // Assign table numbers to regular matches
        $this->assignTableNumbers($matches);

        return $matches;
    }

    /**
     * Sort participants by score for pairing purposes.
     *
     * @param  array<Participant>  $participants
     * @param  array<Standing>  $standings
     * @return array<Participant>
     */
    private function sortParticipantsByScore(array $participants, array $standings, int $roundNumber): array
    {
        // For round 1 or when no standings exist, shuffle randomly
        if ($roundNumber === 1 || count($standings) === 0) {
            $shuffled = $participants;
            shuffle($shuffled);

            return $shuffled;
        }

        // Create a map of participantId -> score for quick lookup
        $scoreMap = [];
        foreach ($standings as $standing) {
            $scoreMap[$standing->participantId()] = $standing->points();
        }

        // Sort by score descending
        usort($participants, function (Participant $a, Participant $b) use ($scoreMap): int {
            $scoreA = $scoreMap[$a->id()->value] ?? 0.0;
            $scoreB = $scoreMap[$b->id()->value] ?? 0.0;

            return $scoreB <=> $scoreA;
        });

        return $participants;
    }

    /**
     * Select participant to receive bye (lowest ranked without previous bye).
     *
     * @param  array<Participant>  $participants  Sorted by score descending
     */
    private function selectByeParticipant(array $participants): Participant
    {
        // Start from the end (lowest ranked) and find first without bye
        $reversed = array_reverse($participants);

        foreach ($reversed as $participant) {
            if (! $participant->hasReceivedBye()) {
                return $participant;
            }
        }

        // If everyone has had a bye, give it to the lowest ranked anyway
        return $reversed[0];
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
