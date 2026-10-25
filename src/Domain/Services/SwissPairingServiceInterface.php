<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;

interface SwissPairingServiceInterface
{
    /**
     * Generate pairings for a new round using the Swiss pairing algorithm.
     *
     * Rules:
     * - Pair players with similar points first
     * - Avoid repeat matchups between players
     * - Assign the BYE as the configuration says (lowest ranked by default) among players below the bye limit
     *
     * @param  array<Participant>  $participants  Active participants who can play
     * @param  array<Standing>  $standings  Current standings sorted by rank
     * @param  array<array{player1Id: string, player2Id: ?string}>  $previousMatchups  Previous match pairs to avoid
     * @param  int  $roundNumber  The round number being generated
     * @param  PairingConfig|null  $config  Method, ordering, rematches and bye rules (defaults when null)
     * @return array<TournamentMatch> Generated matches for the round
     */
    public function generatePairings(
        array $participants,
        array $standings,
        array $previousMatchups,
        int $roundNumber,
        ?PairingConfig $config = null,
    ): array;
}
