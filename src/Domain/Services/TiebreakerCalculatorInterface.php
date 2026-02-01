<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;

interface TiebreakerCalculatorInterface
{
    /**
     * Calculate tiebreaker values for a participant.
     *
     * @param  array<TournamentMatch>  $allMatches  All matches in the tournament
     * @param  array<Standing>  $allStandings  Current standings for all participants
     * @param  array<TiebreakerDefinition>  $tiebreakerConfig  Tiebreaker definitions to calculate
     * @return array<string, float>  Key-value pairs of tiebreaker key and calculated value
     */
    public function calculate(
        string $participantId,
        array $allMatches,
        array $allStandings,
        array $tiebreakerConfig,
    ): array;
}
