<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use InvalidArgumentException;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationResultDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Domain\Exceptions\AlreadyCheckedInException;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\CheckInNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\CheckInWindowClosedException;
use Modules\Tournaments\Domain\Exceptions\GuestEmailBelongsToAccountException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;

interface ParticipantManagementServiceInterface
{
    /**
     * Register a signed-in user to a tournament. Guests go through registerGuest().
     *
     * @throws InvalidArgumentException For a guest registration
     * @throws TournamentNotFoundException
     * @throws TournamentNotOpenException When the status, the configured dates or the event start close registration
     * @throws TournamentFullException
     * @throws AlreadyRegisteredException
     * @throws UserNotAllowedToRegisterException When user doesn't have required role
     */
    public function register(RegisterParticipantDTO $dto): ParticipantResponseDTO;

    /**
     * Register a guest, who must confirm the email before the registration counts for the
     * organisation. The email is compared trimmed and lowercased; a guest whose registration
     * still awaits confirmation gets the email again (at most every few minutes) instead of a
     * second registration.
     *
     * @throws InvalidArgumentException When the registration has a user or lacks the guest name or email
     * @throws TournamentNotFoundException
     * @throws TournamentNotOpenException When the status, the configured dates or the event start close registration
     * @throws GuestRegistrationNotAllowedException When guests are not allowed
     * @throws GuestEmailBelongsToAccountException When the email is an account's that must sign in
     * @throws AlreadyRegisteredException When the email is already registered (or disqualified)
     * @throws TournamentFullException
     */
    public function registerGuest(RegisterParticipantDTO $dto): GuestRegistrationResultDTO;

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
     * @throws CannotWithdrawException When the participant is not active, the tournament is running, finished or cancelled, or its event has started
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
