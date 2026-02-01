<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;
use Modules\Tournaments\Domain\Exceptions\CannotGeneratePairingsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\PreviousRoundNotCompletedException;
use Modules\Tournaments\Domain\Exceptions\RoundNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;

interface RoundManagementServiceInterface
{
    /**
     * Generate pairings for the next round using the Swiss algorithm.
     *
     * @throws TournamentNotFoundException
     * @throws PreviousRoundNotCompletedException
     * @throws CannotGeneratePairingsException
     */
    public function generateNextRound(string $tournamentId): RoundResponseDTO;

    /**
     * Start a round (allows matches to be played).
     *
     * @throws RoundNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function startRound(string $roundId): RoundResponseDTO;

    /**
     * Complete a round (all matches must be reported).
     *
     * @throws RoundNotFoundException
     * @throws InvalidStateTransitionException
     * @throws PreviousRoundNotCompletedException When unreported matches exist
     */
    public function completeRound(string $roundId): RoundResponseDTO;

    /**
     * Get a round by ID.
     */
    public function find(string $roundId): ?RoundResponseDTO;

    /**
     * Get all rounds for a tournament.
     *
     * @return array<RoundResponseDTO>
     */
    public function findByTournament(string $tournamentId): array;

    /**
     * Get the current round for a tournament.
     */
    public function getCurrentRound(string $tournamentId): ?RoundResponseDTO;
}
