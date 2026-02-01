<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\CreateTournamentDTO;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\DTOs\UpdateTournamentDTO;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;

interface TournamentManagementServiceInterface
{
    /**
     * Create a new tournament linked to an event.
     */
    public function create(CreateTournamentDTO $dto): TournamentResponseDTO;

    /**
     * Update tournament settings.
     * Only allowed when tournament is in Draft or RegistrationOpen status.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException When tournament is not in editable state
     */
    public function update(UpdateTournamentDTO $dto): TournamentResponseDTO;

    /**
     * Open registration for a tournament.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function openRegistration(string $tournamentId): TournamentResponseDTO;

    /**
     * Close registration for a tournament.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function closeRegistration(string $tournamentId): TournamentResponseDTO;

    /**
     * Start the tournament.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     * @throws InsufficientParticipantsException
     */
    public function start(string $tournamentId): TournamentResponseDTO;

    /**
     * Finish the tournament.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function finish(string $tournamentId): TournamentResponseDTO;

    /**
     * Cancel the tournament.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function cancel(string $tournamentId): TournamentResponseDTO;

    /**
     * Delete a tournament (only allowed in Draft status).
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function delete(string $tournamentId): void;
}
