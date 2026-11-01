<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\Response\MatchHistoryResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\PlayerMatchResponseDTO;

interface MatchQueryServiceInterface
{
    /**
     * @return array<MatchResponseDTO>
     */
    public function getMatchesForRound(string $roundId): array;

    /**
     * The user's match in the tournament's current round (in progress or about to start), or
     * null when they play none.
     */
    public function findPlayerMatch(string $tournamentId, string $userId): ?PlayerMatchResponseDTO;

    /**
     * Every change of the match's official result, oldest first.
     *
     * @return array<MatchHistoryResponseDTO>
     */
    public function getHistory(string $matchId): array;
}
