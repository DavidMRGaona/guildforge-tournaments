<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use InvalidArgumentException;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationResultDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationResultDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
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
     * Confirm a guest's email through the link of the confirmation email. Only then does the
     * organisation hear of the registration and the guest get the usual registration email. A
     * registration whose deadline passed, or whose tournament closed registration, expires instead;
     * one waiting without a seat expires when the tournament is full. The token survives, so a
     * reused link answers that it was already confirmed.
     */
    public function confirmGuestEmail(string $token): GuestConfirmationResultDTO;

    /**
     * What the confirmation page shows for a link, without changing anything; null for an unknown link.
     *
     * @return array{state: 'pending'|'already_confirmed'|'invalid', tournament_name: string, tournament_slug: string, guest_name: ?string, expires_at: ?string, seat_held: bool}|null
     */
    public function findGuestConfirmation(string $token): ?array;

    /**
     * Add a participant from the panel. The organisation vouches for the guest's email: no
     * confirmation, no fingerprint and no email. The registration window does not apply, but
     * duplicates and capacity do; the guest email is compared trimmed and lowercased.
     *
     * @throws InvalidArgumentException When a guest has no name
     * @throws TournamentNotFoundException
     * @throws AlreadyRegisteredException When the user, or the guest email, already has a registration
     * @throws TournamentFullException When an active registration finds no seat
     */
    public function registerByOrganization(RegisterParticipantDTO $dto, ParticipantStatus $status = ParticipantStatus::Registered): ParticipantResponseDTO;

    /**
     * The organisation vouches for a guest's email from the panel: the guest gets the usual
     * registration email, the organisation no notice. Nothing changes when it is already confirmed.
     *
     * @throws ParticipantNotFoundException
     * @throws TournamentFullException When a registration waiting without a seat finds none
     */
    public function confirmEmailByOrganization(string $participantId): ParticipantResponseDTO;

    /**
     * Confirm a participant's registration from the panel. An unconfirmed guest email counts as
     * confirmed, and the guest gets the usual registration email.
     *
     * @throws ParticipantNotFoundException
     * @throws TournamentFullException When a registration waiting without a seat finds none
     */
    public function confirm(string $participantId): ParticipantResponseDTO;

    /**
     * Check in a registered or confirmed participant from the panel. An unconfirmed guest email
     * counts as confirmed, without an email.
     *
     * @throws ParticipantNotFoundException
     * @throws TournamentFullException When a registration waiting without a seat finds none
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
     * Disqualify an active participant from the panel. An unconfirmed guest email counts as
     * confirmed, without an email.
     *
     * @throws ParticipantNotFoundException
     */
    public function disqualify(string $participantId, ?string $reason = null): ParticipantResponseDTO;

    /**
     * Bulk check-in multiple participants, as checkIn() does for each; unknown IDs are skipped.
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
     * Whether a participant in this status may be checked in, from the panel or by themselves:
     * with manual confirmation only confirmed participants, otherwise registered ones too.
     */
    public function canCheckIn(ParticipantStatus $status, bool $requiresManualConfirmation): bool;

    /**
     * Public self check-in by email: the active guest registration with that email, or else the
     * active registration of the account with it. Inactive rows are ignored. Follows canCheckIn().
     *
     * @throws ParticipantNotFoundException When no active participant has that email
     * @throws CheckInNotAllowedException When self check-in is disabled for the tournament, the
     *                                     guest registration found has not confirmed its email, or
     *                                     the organisation has not confirmed the registration yet
     * @throws CheckInWindowClosedException When check-in window is not active
     * @throws AlreadyCheckedInException When participant has already checked in
     */
    public function checkInByEmail(string $tournamentId, string $email): ParticipantResponseDTO;

    /**
     * Public self check-in of a signed-in user: the user's own active registration, by user id.
     * Same rules as checkInByEmail().
     *
     * @throws ParticipantNotFoundException When the user has no active registration
     * @throws CheckInNotAllowedException
     * @throws CheckInWindowClosedException
     * @throws AlreadyCheckedInException
     */
    public function checkInByUser(string $tournamentId, string $userId): ParticipantResponseDTO;

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
