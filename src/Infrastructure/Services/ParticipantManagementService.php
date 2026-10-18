<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationOutcome;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationResultDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationOutcome;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationResultDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\GuestAccountPolicy;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
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
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

final readonly class ParticipantManagementService implements ParticipantManagementServiceInterface
{
    /** Minutes before a guest resubmitting the form gets the confirmation email again. */
    private const int RESEND_MARGIN_MINUTES = 5;

    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
        private RegistrationLockInterface $lock = new DatabaseRegistrationLock,
        private GuestRegistrationSettingsInterface $guestSettings = new GuestRegistrationSettings,
    ) {}

    public function register(RegisterParticipantDTO $dto): ParticipantResponseDTO
    {
        $userId = $dto->userId;

        if ($userId === null) {
            throw new InvalidArgumentException('Guests register through registerGuest().');
        }

        $now = CarbonImmutable::now();

        /** @var array{ParticipantResponseDTO, ParticipantRegistered|null} $outcome */
        $outcome = $this->lock->forTournament(
            $dto->tournamentId,
            fn (): array => $this->placeUser($dto, $userId, $now),
        );

        [$response, $registered] = $outcome;

        // Mailed once the registration is committed
        if ($registered !== null) {
            event($registered);
        }

        return $response;
    }

    public function registerGuest(RegisterParticipantDTO $dto): GuestRegistrationResultDTO
    {
        $name = $dto->guestName;
        $email = $dto->guestEmail;

        if (! $dto->isGuest() || $name === null || $email === null) {
            throw new InvalidArgumentException('A guest registration needs a guest name and email and no user.');
        }

        $email = mb_strtolower(trim($email));
        $now = CarbonImmutable::now();

        /** @var array{GuestRegistrationResultDTO, GuestConfirmationRequested|null} $outcome */
        $outcome = $this->lock->forTournament(
            $dto->tournamentId,
            fn (): array => $this->placeGuest($dto, $name, $email, $now),
        );

        [$result, $confirmationRequest] = $outcome;

        // Mailed once the registration is committed; the organisation hears of it only after the confirmation
        if ($confirmationRequest !== null) {
            event($confirmationRequest);
        }

        return $result;
    }

    public function confirmGuestEmail(string $token): GuestConfirmationResultDTO
    {
        $now = CarbonImmutable::now();

        /** @var array{GuestConfirmationResultDTO, ParticipantRegistered|null} $outcome */
        $outcome = $this->lock->forConfirmationToken(
            $token,
            fn (): array => $this->confirmUnderLock($token, $now),
        );

        [$result, $registered] = $outcome;

        // Mailed once the confirmation is committed
        if ($registered !== null) {
            event($registered);
        }

        return $result;
    }

    public function findGuestConfirmation(string $token): ?array
    {
        $participant = $this->participantRepository->findByConfirmationToken($token);

        if ($participant === null) {
            return null;
        }

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($tournament === null) {
            return null;
        }

        $expiresAt = $participant->confirmationExpiresAt();

        $state = match (true) {
            ! $participant->isActive() => 'invalid',
            $participant->emailConfirmedAt() !== null => 'already_confirmed',
            $this->isOverdue($participant, CarbonImmutable::now()) => 'invalid',
            default => 'pending',
        };

        return [
            'state' => $state,
            'tournament_name' => $tournament->name(),
            'tournament_slug' => $tournament->slug(),
            'guest_name' => $participant->guestName(),
            'expires_at' => $state === 'pending' ? $expiresAt?->format('c') : null,
            'seat_held' => $state === 'pending' && $participant->holdsSeat(),
        ];
    }

    public function confirm(string $participantId): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($participantId);
        }

        $participant->confirm();
        $this->participantRepository->save($participant);

        return $this->createResponseDTO($participant);
    }

    public function checkIn(string $participantId): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($participantId);
        }

        $participant->checkIn();
        $this->participantRepository->save($participant);

        return $this->createResponseDTO($participant);
    }

    public function withdraw(string $participantId): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($participantId);
        }

        $this->ensureCanWithdraw($participant);

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($participant->tournamentId());
        }

        $this->ensureTournamentAllowsWithdrawal($tournament, $participantId);

        // Get participant info before withdrawal for notification
        $participantName = $this->getParticipantName($participant);
        $participantEmail = $this->getParticipantEmail($participant);

        $participant->withdraw();
        $this->participantRepository->save($participant);

        // Dispatch withdrawal event for notifications
        event(ParticipantWithdrawn::create(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: $participant->userId(),
            participantEmail: $participantEmail,
            participantName: $participantName,
        ));

        return $this->createResponseDTO($participant);
    }

    public function findByToken(string $token): ?ParticipantResponseDTO
    {
        $participant = $this->participantRepository->findByCancellationToken($token);

        if ($participant === null) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function withdrawByToken(string $token): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->findByCancellationToken($token);

        if ($participant === null) {
            throw ParticipantNotFoundException::byToken($token);
        }

        $this->ensureCanWithdraw($participant);

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($participant->tournamentId());
        }

        $this->ensureTournamentAllowsWithdrawal($tournament, $participant->id()->value);

        // Get participant info before withdrawal for notification
        $participantName = $this->getParticipantName($participant);
        $participantEmail = $this->getParticipantEmail($participant);

        $participant->withdraw();
        $this->participantRepository->save($participant);

        // Dispatch withdrawal event for notifications
        event(ParticipantWithdrawn::create(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: $participant->userId(),
            participantEmail: $participantEmail,
            participantName: $participantName,
        ));

        return $this->createResponseDTO($participant);
    }

    public function disqualify(string $participantId, ?string $reason = null): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($participantId);
        }

        $participant->disqualify();
        $this->participantRepository->save($participant);

        return $this->createResponseDTO($participant);
    }

    /**
     * @param  array<string>  $participantIds
     * @return array<ParticipantResponseDTO>
     */
    public function bulkCheckIn(array $participantIds): array
    {
        $results = [];

        foreach ($participantIds as $participantId) {
            $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

            if ($participant === null) {
                continue;
            }

            $participant->checkIn();
            $this->participantRepository->save($participant);

            $results[] = $this->createResponseDTO($participant);
        }

        return $results;
    }

    public function find(string $participantId): ?ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if ($participant === null) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function findByUserAndTournament(string $userId, string $tournamentId): ?ParticipantResponseDTO
    {
        $participant = $this->participantRepository->findByUserAndTournament($userId, $tournamentId);

        if ($participant === null) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function checkInByEmail(string $tournamentId, string $email): ParticipantResponseDTO
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($tournamentId));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($tournamentId);
        }

        // Validate self check-in is allowed
        if (! $tournament->selfCheckInAllowed()) {
            throw CheckInNotAllowedException::selfCheckInDisabled($tournamentId);
        }

        if (! $tournament->requiresCheckIn()) {
            throw CheckInNotAllowedException::checkInNotRequired($tournamentId);
        }

        // Validate check-in window
        $this->validateCheckInWindow($tournament);

        // Find participant by email
        $participant = $this->participantRepository->findByEmailAndTournament($email, $tournamentId);

        if ($participant === null) {
            throw ParticipantNotFoundException::withEmail($email, $tournamentId);
        }

        // Check if already checked in
        if ($participant->status() === ParticipantStatus::CheckedIn) {
            throw AlreadyCheckedInException::forParticipant($participant->id()->value);
        }

        // Perform check-in
        $participant->checkIn();
        $this->participantRepository->save($participant);

        return $this->createResponseDTO($participant);
    }

    private function validateCheckInWindow(Tournament $tournament): void
    {
        // If tournament is already in progress or finished, check-in is closed
        if (in_array($tournament->status(), [TournamentStatus::InProgress, TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            throw CheckInWindowClosedException::tournamentStarted($tournament->id()->value);
        }

        $eventStartDate = $tournament->eventStartsAt();

        if ($eventStartDate === null) {
            throw CheckInWindowClosedException::alreadyClosed($tournament->id()->value);
        }

        $checkInMinutesBefore = $tournament->checkInStartsBefore() ?? 30;
        $windowOpensAt = $eventStartDate->modify("-{$checkInMinutesBefore} minutes");

        $now = new DateTimeImmutable;

        if ($now < $windowOpensAt) {
            throw CheckInWindowClosedException::notYetOpen($tournament->id()->value);
        }

        if ($now > $eventStartDate) {
            throw CheckInWindowClosedException::alreadyClosed($tournament->id()->value);
        }
    }

    /**
     * Why registration is closed: the status first, then the event start (which caps any
     * closing date) and the configured dates. An unknown start reads as a plain closed registration.
     */
    private function registrationClosedReason(Tournament $tournament, DateTimeImmutable $now): TournamentNotOpenException
    {
        $tournamentId = $tournament->id()->value;
        $opensAt = $tournament->registrationOpensAt();

        return match (true) {
            $tournament->status() === TournamentStatus::Draft => TournamentNotOpenException::notYetOpen($tournamentId),
            $tournament->status() === TournamentStatus::InProgress => TournamentNotOpenException::inProgress($tournamentId),
            $tournament->status() === TournamentStatus::Finished => TournamentNotOpenException::finished($tournamentId),
            $tournament->status() === TournamentStatus::Cancelled => TournamentNotOpenException::cancelled($tournamentId),
            $tournament->status() !== TournamentStatus::RegistrationOpen => TournamentNotOpenException::alreadyClosed($tournamentId),
            $tournament->eventStartsAt() === null => TournamentNotOpenException::alreadyClosed($tournamentId),
            $tournament->hasEventStarted($now) => TournamentNotOpenException::eventStarted($tournamentId),
            $opensAt !== null && $now < $opensAt => TournamentNotOpenException::notYetOpen($tournamentId),
            default => TournamentNotOpenException::alreadyClosed($tournamentId),
        };
    }

    /**
     * Withdrawing from the web follows Tournament::canWithdraw(); the exception tells the
     * controllers why. The panel's staff withdrawal updates the participant directly and skips this.
     *
     * @throws CannotWithdrawException
     */
    private function ensureTournamentAllowsWithdrawal(Tournament $tournament, string $participantId): void
    {
        if ($tournament->canWithdraw()) {
            return;
        }

        throw match (true) {
            $tournament->status() === TournamentStatus::InProgress => CannotWithdrawException::tournamentInProgress($participantId),
            $tournament->status() === TournamentStatus::Finished => CannotWithdrawException::tournamentFinished($participantId),
            $tournament->status() === TournamentStatus::Cancelled => CannotWithdrawException::tournamentCancelled($participantId),
            $tournament->eventStartsAt() === null => CannotWithdrawException::eventStartUnknown($participantId),
            default => CannotWithdrawException::tournamentStarted($participantId),
        };
    }

    /**
     * @throws AlreadyRegisteredException
     */
    private function rejectDisqualified(Participant $participant): void
    {
        if ($participant->status() === ParticipantStatus::Disqualified) {
            throw AlreadyRegisteredException::disqualified($participant->tournamentId(), $participant->id()->value);
        }
    }

    /**
     * A second submit of the cancel form, or a disqualified participant, gets the
     * domain error the controllers show instead of an invalid state transition (500).
     *
     * @throws CannotWithdrawException
     */
    private function ensureCanWithdraw(Participant $participant): void
    {
        if ($participant->status() === ParticipantStatus::Disqualified) {
            throw CannotWithdrawException::disqualified($participant->id()->value);
        }

        if (! $this->isActiveParticipant($participant)) {
            throw CannotWithdrawException::alreadyWithdrawn($participant->id()->value);
        }
    }

    private function isActiveParticipant(Participant $participant): bool
    {
        $activeStatuses = [
            ParticipantStatus::Registered,
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        return in_array($participant->status(), $activeStatuses, true);
    }

    /**
     * The user registration itself, under the tournament lock: the user's own rules first, then an
     * active guest registration of the account email, then capacity and the user's row.
     *
     * @return array{ParticipantResponseDTO, ParticipantRegistered|null}
     *
     * @throws TournamentNotFoundException
     * @throws TournamentNotOpenException
     * @throws AlreadyRegisteredException
     * @throws UserNotAllowedToRegisterException
     * @throws TournamentFullException
     */
    private function placeUser(RegisterParticipantDTO $dto, string $userId, CarbonImmutable $now): array
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($dto->tournamentId));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($dto->tournamentId);
        }

        if (! $tournament->isRegistrationOpen($now)) {
            throw $this->registrationClosedReason($tournament, $now);
        }

        $existingParticipant = $this->participantRepository->findByUserAndTournament($userId, $dto->tournamentId);
        if ($existingParticipant !== null) {
            $this->rejectDisqualified($existingParticipant);

            if ($this->isActiveParticipant($existingParticipant)) {
                throw AlreadyRegisteredException::userAlreadyRegistered($dto->tournamentId, $userId);
            }
        }

        $allowedRoles = $tournament->allowedRoles();
        if ($allowedRoles !== [] && ! $this->userDataProvider->userHasAnyRole($userId, $allowedRoles)) {
            throw UserNotAllowedToRegisterException::missingRequiredRole($dto->tournamentId, $userId, $allowedRoles);
        }

        $guestRegistration = $this->activeGuestRegistrationOfAccount($userId, $dto->tournamentId, $now);
        if ($guestRegistration !== null) {
            return $this->takeOverGuestRegistration($tournament, $guestRegistration, $existingParticipant, $userId, $dto, $now);
        }

        $this->ensureCapacity($tournament);

        if ($existingParticipant !== null) {
            // Withdrawn earlier: the same row comes back
            $participant = $existingParticipant;
            $participant->reactivate();
        } else {
            $participant = new Participant(
                id: ParticipantId::generate(),
                tournamentId: $dto->tournamentId,
                status: ParticipantStatus::Registered,
                userId: $userId,
                seed: $dto->seed,
                hasReceivedBye: false,
                registeredAt: $now->toDateTimeImmutable(),
            );
        }

        $this->recordFingerprint($participant, $dto);
        $this->participantRepository->save($participant);

        return [
            $this->createResponseDTO($participant),
            $this->userRegistered($participant, $userId),
        ];
    }

    /**
     * The active guest registration made with the account's email, if any. One whose hold lapsed
     * before the expiry command got to it ends here instead, as it would for a guest signing up again.
     */
    private function activeGuestRegistrationOfAccount(string $userId, string $tournamentId, CarbonImmutable $now): ?Participant
    {
        $email = $this->userDataProvider->getUserInfo($userId)['email'] ?? null;

        if ($email === null || trim($email) === '') {
            return null;
        }

        $guestRegistration = $this->participantRepository->findByGuestEmailAndTournament(mb_strtolower(trim($email)), $tournamentId);

        if ($guestRegistration === null || ! $guestRegistration->isActive()) {
            return null;
        }

        $this->expireIfOverdue($guestRegistration, $now);

        return $guestRegistration->isActive() ? $guestRegistration : null;
    }

    /**
     * The user takes over the guest registration of their email when the account email is verified:
     * the place is kept and the email counts as confirmed. The organisation is told only if it never
     * heard of the guest registration, i.e. it was still awaiting confirmation.
     *
     * @return array{ParticipantResponseDTO, ParticipantRegistered|null}
     *
     * @throws AlreadyRegisteredException when the account email is not verified
     * @throws TournamentFullException when a registration waiting without a seat finds none
     */
    private function takeOverGuestRegistration(
        Tournament $tournament,
        Participant $guestRegistration,
        ?Participant $ownRegistration,
        string $userId,
        RegisterParticipantDTO $dto,
        CarbonImmutable $now,
    ): array {
        $account = $this->userDataProvider->findAccountByEmail((string) $guestRegistration->guestEmail());

        // Anyone can create an unverified account with someone else's email: it proves nothing
        if ($account === null || $account['id'] !== $userId || ! $account['email_verified']) {
            throw AlreadyRegisteredException::guestWithAccountEmail($tournament->id()->value);
        }

        if (! $guestRegistration->holdsSeat()) {
            $this->ensureCapacity($tournament);
        }

        $organizerNeverTold = $guestRegistration->isAwaitingEmailConfirmation();

        if ($ownRegistration !== null) {
            // One row per user and tournament: the user's ended row takes the guest's place
            $participant = $ownRegistration;
            $participant->takeOverGuestRegistration($guestRegistration, $now->toDateTimeImmutable());

            $guestRegistration->handOverToAccount();
            $this->participantRepository->save($guestRegistration);
        } else {
            $participant = $guestRegistration;
            $participant->claimForUser($userId, $now->toDateTimeImmutable());
        }

        $this->recordFingerprint($participant, $dto);
        $this->participantRepository->save($participant);

        return [
            $this->createResponseDTO($participant, claimedGuestRegistration: true),
            $organizerNeverTold ? $this->userRegistered($participant, $userId) : null,
        ];
    }

    /**
     * @throws TournamentFullException
     */
    private function ensureCapacity(Tournament $tournament): void
    {
        $maxParticipants = $tournament->maxParticipants();

        if ($maxParticipants !== null && $this->isFull($tournament)) {
            throw TournamentFullException::maxParticipantsReached($tournament->id()->value, $maxParticipants);
        }
    }

    /**
     * Whether every seat is taken; registrations waiting without a seat do not count.
     */
    private function isFull(Tournament $tournament): bool
    {
        $maxParticipants = $tournament->maxParticipants();

        return $maxParticipants !== null
            && $this->participantRepository->countActiveByTournament($tournament->id()->value) >= $maxParticipants;
    }

    /**
     * A web registration stores the browser fingerprint; one without it keeps what the row had.
     */
    private function recordFingerprint(Participant $participant, RegisterParticipantDTO $dto): void
    {
        if ($dto->fingerprint !== null) {
            $participant->recordFingerprint($dto->fingerprint);
        }
    }

    private function userRegistered(Participant $participant, string $userId): ParticipantRegistered
    {
        return ParticipantRegistered::create(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: $userId,
            guestEmail: null,
            isGuest: false,
        );
    }

    /**
     * The guest registration itself, under the tournament lock.
     *
     * @return array{GuestRegistrationResultDTO, GuestConfirmationRequested|null}
     *
     * @throws TournamentNotFoundException
     * @throws TournamentNotOpenException
     * @throws GuestRegistrationNotAllowedException
     * @throws GuestEmailBelongsToAccountException
     * @throws AlreadyRegisteredException
     * @throws TournamentFullException
     */
    private function placeGuest(RegisterParticipantDTO $dto, string $name, string $email, CarbonImmutable $now): array
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($dto->tournamentId));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($dto->tournamentId);
        }

        if (! $tournament->isRegistrationOpen($now)) {
            throw $this->registrationClosedReason($tournament, $now);
        }

        if (! $tournament->allowGuests()) {
            throw GuestRegistrationNotAllowedException::guestsNotAllowed($dto->tournamentId);
        }

        $this->ensureEmailIsNotAnAccounts($tournament, $email);

        $existing = $this->participantRepository->findByGuestEmailAndTournament($email, $dto->tournamentId);

        if ($existing !== null) {
            $this->rejectDisqualified($existing);
            $this->expireIfOverdue($existing, $now);

            if ($existing->isActive()) {
                if (! $existing->isAwaitingEmailConfirmation()) {
                    throw AlreadyRegisteredException::guestWithEmailAlreadyRegistered($dto->tournamentId, $email);
                }

                return $this->answerPendingGuest($existing, $now);
            }
        }

        $this->ensureCapacity($tournament);

        $terms = GuestConfirmationTerms::for(
            $tournament,
            $this->participantRepository->countUnconfirmedHeldByTournament($dto->tournamentId),
            $this->guestSettings,
            $now,
        );

        if ($existing !== null) {
            // A withdrawn or expired registration of the email comes back with the new submission's name
            $participant = $existing;
            $participant->reactivate();
            $participant->renameGuest($name);
            $participant->setCancellationToken(bin2hex(random_bytes(32)));
        } else {
            $participant = new Participant(
                id: ParticipantId::generate(),
                tournamentId: $dto->tournamentId,
                status: ParticipantStatus::Registered,
                guestName: $name,
                guestEmail: $email,
                cancellationToken: bin2hex(random_bytes(32)),
                seed: $dto->seed,
                hasReceivedBye: false,
                registeredAt: $now->toDateTimeImmutable(),
            );
        }

        $confirmationToken = bin2hex(random_bytes(32));
        $participant->recordFingerprint($dto->fingerprint);
        $participant->startEmailConfirmation($confirmationToken, $terms['mode'], $terms['expiresAt'], $now->toDateTimeImmutable());
        $this->participantRepository->save($participant);

        $seatHeld = $terms['mode'] === GuestConfirmationMode::Hold;

        return [
            new GuestRegistrationResultDTO(
                participantId: $participant->id()->value,
                tournamentId: $dto->tournamentId,
                outcome: GuestRegistrationOutcome::ConfirmationSent,
                seatHeld: $seatHeld,
                expiresAt: $terms['expiresAt'],
            ),
            new GuestConfirmationRequested(
                participantId: $participant->id()->value,
                tournamentId: $dto->tournamentId,
                guestEmail: $email,
                guestName: $name,
                confirmationToken: $confirmationToken,
                expiresAt: $terms['expiresAt'],
                seatHeld: $seatHeld,
            ),
        ];
    }

    /**
     * A held seat whose deadline passed before the expiry command got to it ends here, under the
     * lock, so the submission becomes a new registration instead of resending a dead link. Saved
     * before the capacity check so its seat is free. No expiry email: the guest is signing up again.
     */
    private function expireIfOverdue(Participant $participant, CarbonImmutable $now): void
    {
        if (! $this->isOverdue($participant, $now)) {
            return;
        }

        $participant->expire();
        $this->participantRepository->save($participant);
    }

    /**
     * A registration still awaiting confirmation whose held seat is past its deadline.
     */
    private function isOverdue(Participant $participant, CarbonImmutable $now): bool
    {
        $expiresAt = $participant->confirmationExpiresAt();

        return $participant->isAwaitingEmailConfirmation()
            && $participant->status() === ParticipantStatus::Registered
            && $expiresAt !== null
            && $expiresAt <= $now;
    }

    /**
     * The confirmation itself, under the tournament lock. A registration still in the state the
     * form left it lapses instead when registration closed, its deadline passed, or it waits
     * without a seat in a full tournament.
     *
     * @return array{GuestConfirmationResultDTO, ParticipantRegistered|null}
     */
    private function confirmUnderLock(string $token, CarbonImmutable $now): array
    {
        $participant = $this->participantRepository->findByConfirmationToken($token);
        $tournament = $participant === null
            ? null
            : $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($participant === null || $tournament === null) {
            return [new GuestConfirmationResultDTO(GuestConfirmationOutcome::Invalid, null, null), null];
        }

        $answer = static fn (GuestConfirmationOutcome $outcome): GuestConfirmationResultDTO => new GuestConfirmationResultDTO(
            $outcome,
            $tournament->id()->value,
            $tournament->slug(),
        );

        if (! $participant->isActive()) {
            return [$answer(GuestConfirmationOutcome::Invalid), null];
        }

        if (! $participant->isAwaitingEmailConfirmation()) {
            return [$answer(GuestConfirmationOutcome::AlreadyConfirmed), null];
        }

        // A row the organisation already moved on (confirmed, checked in) has been vouched for: it never lapses here
        if ($participant->status() === ParticipantStatus::Registered) {
            $lapse = match (true) {
                ! $tournament->isRegistrationOpen($now) => GuestConfirmationOutcome::RegistrationClosed,
                $this->isOverdue($participant, $now) => GuestConfirmationOutcome::Invalid,
                ! $participant->holdsSeat() && $this->isFull($tournament) => GuestConfirmationOutcome::Full,
                default => null,
            };

            if ($lapse !== null) {
                $participant->expire();
                $this->participantRepository->save($participant);

                return [$answer($lapse), null];
            }
        }

        $participant->markEmailConfirmed($now->toDateTimeImmutable());
        $this->participantRepository->save($participant);

        return [
            $answer(GuestConfirmationOutcome::Confirmed),
            ParticipantRegistered::create(
                participantId: $participant->id()->value,
                tournamentId: $participant->tournamentId(),
                userId: null,
                guestEmail: $participant->guestEmail(),
                isGuest: true,
                cancellationToken: $participant->cancellationToken(),
            ),
        ];
    }

    /**
     * The email of an account only matters when it is verified and that account could register
     * as a user here; otherwise the submitter signs up as any other guest.
     *
     * @throws GuestEmailBelongsToAccountException
     * @throws AlreadyRegisteredException
     */
    private function ensureEmailIsNotAnAccounts(Tournament $tournament, string $email): void
    {
        $account = $this->userDataProvider->findAccountByEmail($email);

        // Anyone can create an unverified account with someone else's email: it proves nothing
        if ($account === null || ! $account['email_verified']) {
            return;
        }

        $allowedRoles = $tournament->allowedRoles();
        if ($allowedRoles !== [] && ! $this->userDataProvider->userHasAnyRole($account['id'], $allowedRoles)) {
            return;
        }

        $tournamentId = $tournament->id()->value;

        if ($this->guestSettings->accountPolicy() === GuestAccountPolicy::RequireLogin) {
            throw GuestEmailBelongsToAccountException::forTournament($tournamentId);
        }

        $registration = $this->participantRepository->findByUserAndTournament($account['id'], $tournamentId);
        if ($registration !== null && $registration->isActive()) {
            throw AlreadyRegisteredException::userAlreadyRegistered($tournamentId, $account['id']);
        }
    }

    /**
     * A guest whose registration still awaits confirmation gets the email again, at most once
     * every few minutes. Nothing else changes: the deadline, the name and the tokens stay.
     *
     * @return array{GuestRegistrationResultDTO, GuestConfirmationRequested|null}
     */
    private function answerPendingGuest(Participant $participant, CarbonImmutable $now): array
    {
        $sentAt = $participant->confirmationSentAt();
        $token = $participant->confirmationToken();
        $resend = $token !== null
            && ($sentAt === null || $sentAt <= $now->subMinutes(self::RESEND_MARGIN_MINUTES));

        $result = new GuestRegistrationResultDTO(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            outcome: $resend ? GuestRegistrationOutcome::ConfirmationResent : GuestRegistrationOutcome::ConfirmationPending,
            seatHeld: $participant->holdsSeat(),
            expiresAt: $participant->confirmationExpiresAt(),
        );

        if (! $resend) {
            return [$result, null];
        }

        $participant->markConfirmationSent($now->toDateTimeImmutable());
        $this->participantRepository->save($participant);

        return [$result, new GuestConfirmationRequested(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            guestEmail: (string) $participant->guestEmail(),
            guestName: (string) $participant->guestName(),
            confirmationToken: $token,
            expiresAt: $participant->confirmationExpiresAt(),
            seatHeld: $participant->holdsSeat(),
        )];
    }

    private function createResponseDTO(Participant $participant, bool $claimedGuestRegistration = false): ParticipantResponseDTO
    {
        $userInfo = $participant->userId() !== null
            ? $this->userDataProvider->getUserInfo($participant->userId())
            : null;

        return ParticipantResponseDTO::fromEntity(
            $participant,
            $userInfo['name'] ?? null,
            $userInfo['email'] ?? null,
            $claimedGuestRegistration,
        );
    }

    private function getParticipantName(Participant $participant): string
    {
        if ($participant->userId() !== null) {
            $userInfo = $this->userDataProvider->getUserInfo($participant->userId());

            return $userInfo['name'] ?? __('tournaments::messages.participants.unknown');
        }

        return $participant->guestName() ?? __('tournaments::messages.participants.unknown');
    }

    private function getParticipantEmail(Participant $participant): ?string
    {
        if ($participant->guestEmail() !== null) {
            return $participant->guestEmail();
        }

        if ($participant->userId() !== null) {
            $userInfo = $this->userDataProvider->getUserInfo($participant->userId());

            return $userInfo['email'] ?? null;
        }

        return null;
    }
}
