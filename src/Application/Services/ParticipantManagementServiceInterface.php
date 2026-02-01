<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Domain\Exceptions\AlreadyCheckedInException;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\CheckInNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\CheckInWindowClosedException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;

interface ParticipantManagementServiceInterface
{
    /**
     * Register a user or guest to a tournament.
     *
     * @throws TournamentNotFoundException
     * @throws TournamentNotOpenException
     * @throws TournamentFullException
     * @throws AlreadyRegisteredException
     * @throws UserNotAllowedToRegisterException When user doesn't have required role
     * @throws GuestRegistrationNotAllowedException When guests are not allowed
     */
    public function register(RegisterParticipantDTO $dto): ParticipantResponseDTO;

    /**
     * Confirm a participant's registration.
     *
     * @throws ParticipantNotFoundException
     */
    public function confirm(string $participantId): ParticipantResponseDTO;

    /**
     * Check-in a participant.
     *
     * @throws ParticipantNotFoundException
     */
    public function checkIn(string $participantId): ParticipantResponseDTO;

    /**
     * Withdraw a participant from the tournament.
     *
     * @throws ParticipantNotFoundException
     * @throws CannotWithdrawException When tournament has already started
     */
    public function withdraw(string $participantId): ParticipantResponseDTO;

    /**
     * Disqualify a participant.
     *
     * @throws ParticipantNotFoundException
     */
    public function disqualify(string $participantId, ?string $reason = null): ParticipantResponseDTO;

    /**
     * Bulk check-in multiple participants.
     *
     * @param  array<string>  $participantIds
     * @return array<ParticipantResponseDTO>
     */
    public function bulkCheckIn(array $participantIds): array;

    /**
     * Get a participant by ID.
     */
    public function find(string $participantId): ?ParticipantResponseDTO;

    /**
     * Get a user's registration for a tournament.
     */
    public function findByUserAndTournament(string $userId, string $tournamentId): ?ParticipantResponseDTO;

    /**
     * Check-in a participant by email (for public self check-in).
     * Finds participant by email (guest_email or user email) and performs check-in.
     *
     * @throws ParticipantNotFoundException When no participant found with that email
     * @throws CheckInNotAllowedException When self check-in is disabled for the tournament
     * @throws CheckInWindowClosedException When check-in window is not active
     * @throws AlreadyCheckedInException When participant has already checked in
     */
    public function checkInByEmail(string $tournamentId, string $email): ParticipantResponseDTO;

    /**
     * Find a participant by cancellation token.
     */
    public function findByToken(string $token): ?ParticipantResponseDTO;

    /**
     * Withdraw a participant using their cancellation token.
     *
     * @throws ParticipantNotFoundException When token is invalid
     * @throws CannotWithdrawException When participant cannot withdraw
     */
    public function withdrawByToken(string $token): ParticipantResponseDTO;
}
