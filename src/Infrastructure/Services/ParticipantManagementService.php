<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
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

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($dto->tournamentId));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($dto->tournamentId);
        }

        $now = new DateTimeImmutable;

        if (! $tournament->isRegistrationOpen($now)) {
            throw $this->registrationClosedReason($tournament, $now);
        }

        if ($tournament->maxParticipants() !== null) {
            $currentCount = $this->participantRepository->countActiveByTournament($dto->tournamentId);
            if ($currentCount >= $tournament->maxParticipants()) {
                throw TournamentFullException::maxParticipantsReached($dto->tournamentId, $tournament->maxParticipants());
            }
        }

        $existingParticipant = $this->participantRepository->findByUserAndTournament(
            $userId,
            $dto->tournamentId
        );
        if ($existingParticipant !== null) {
            $this->rejectDisqualified($existingParticipant);

            if ($this->isActiveParticipant($existingParticipant)) {
                throw AlreadyRegisteredException::userAlreadyRegistered($dto->tournamentId, $userId);
            }
            if ($existingParticipant->status() === ParticipantStatus::Withdrawn) {
                return $this->reactivateParticipant($existingParticipant);
            }
        }

        $allowedRoles = $tournament->allowedRoles();
        if ($allowedRoles !== [] && ! $this->userDataProvider->userHasAnyRole($userId, $allowedRoles)) {
            throw UserNotAllowedToRegisterException::missingRequiredRole($dto->tournamentId, $userId, $allowedRoles);
        }

        $participant = new Participant(
            id: ParticipantId::generate(),
            tournamentId: $dto->tournamentId,
            status: ParticipantStatus::Registered,
            userId: $userId,
            seed: $dto->seed,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable,
        );

        $this->participantRepository->save($participant);

        // Dispatch registration event for notifications
        event(ParticipantRegistered::create(
            participantId: $participant->id()->value,
            tournamentId: $dto->tournamentId,
            userId: $userId,
            guestEmail: null,
            isGuest: false,
        ));

        return $this->createResponseDTO($participant);
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

    private function reactivateParticipant(Participant $participant): ParticipantResponseDTO
    {
        $participant->reactivate();
        $this->participantRepository->save($participant);

        event(ParticipantRegistered::create(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: $participant->userId(),
            guestEmail: $participant->guestEmail(),
            isGuest: $participant->isGuest(),
        ));

        return $this->createResponseDTO($participant);
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

        if ($tournament->maxParticipants() !== null) {
            $currentCount = $this->participantRepository->countActiveByTournament($dto->tournamentId);
            if ($currentCount >= $tournament->maxParticipants()) {
                throw TournamentFullException::maxParticipantsReached($dto->tournamentId, $tournament->maxParticipants());
            }
        }

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
        $expiresAt = $participant->confirmationExpiresAt();

        if (! $participant->isAwaitingEmailConfirmation()
            || $participant->status() !== ParticipantStatus::Registered
            || $expiresAt === null
            || $expiresAt > $now) {
            return;
        }

        $participant->expire();
        $this->participantRepository->save($participant);
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

    private function createResponseDTO(Participant $participant): ParticipantResponseDTO
    {
        $userInfo = $participant->userId() !== null
            ? $this->userDataProvider->getUserInfo($participant->userId())
            : null;

        return ParticipantResponseDTO::fromEntity(
            $participant,
            $userInfo['name'] ?? null,
            $userInfo['email'] ?? null
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
