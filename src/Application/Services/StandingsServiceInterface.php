<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;

interface StandingsServiceInterface
{
    /**
     * Recalculate the standings of a tournament from its played matches and store them.
     *
     * @return array<Standing> The stored standings, sorted by rank
     *
     * @throws TournamentNotFoundException
     */
    public function recalculate(string $tournamentId): array;

    /**
     * The points a bye gives in the tournament, from the scoring rules the standings use.
     *
     * @throws TournamentNotFoundException
     */
    public function byePoints(string $tournamentId): float;
}
