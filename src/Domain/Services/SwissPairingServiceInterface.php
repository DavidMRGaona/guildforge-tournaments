<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;

interface SwissPairingServiceInterface
{
    /**
     * Generate pairings for a new round using the Swiss pairing algorithm.
     *
     * Rules:
     * - Pair players with similar points first
     * - Avoid repeat matchups between players
     * - Assign BYE to lowest-ranked player without previous BYE
     * - One BYE per player maximum throughout the tournament
     *
     * @param  array<Participant>  $participants  Active participants who can play
     * @param  array<Standing>  $standings  Current standings sorted by rank
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups  Previous match pairs to avoid
     * @param  int  $roundNumber  The round number being generated
     * @return array<TournamentMatch>  Generated matches for the round
     */
    public function generatePairings(
        array $participants,
        array $standings,
        array $previousMatchups,
        int $roundNumber,
    ): array;
}
