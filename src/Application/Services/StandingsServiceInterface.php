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
}
