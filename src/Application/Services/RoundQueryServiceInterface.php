<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;

interface RoundQueryServiceInterface
{
    /**
     * Get all rounds for a tournament with match information.
     *
     * @return array<RoundResponseDTO>
     */
    public function getRoundsWithMatches(string $tournamentId): array;

    /**
     * Get the current active round for a tournament.
     */
    public function getCurrentRound(string $tournamentId): ?RoundResponseDTO;
}
