<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\MatchHistory;

interface MatchHistoryRepositoryInterface
{
    /**
     * Save a match history entry.
     */
    public function save(MatchHistory $history): void;

    /**
     * Find all history entries for a match.
     *
     * @return array<MatchHistory>
     */
    public function findByMatch(string $matchId): array;
}
