<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
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
use Modules\Tournaments\Domain\Events\ParticipantDisqualified;
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
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Notifications\GuestRegistrationExpiredNotification;
use Throwable;

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
        private Clock $clock = new SystemClock,
    ) {}

    public function register(RegisterParticipantDTO $dto): ParticipantResponseDTO
    {
        $userId = $dto->userId;

        if ($userId === null) {
            throw new InvalidArgumentException('Guests register through registerGuest().');
        }

        $now = $this->now();

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
        $now = $this->now();

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
        $now = $this->now();

        /** @var array{GuestConfirmationResultDTO, ParticipantRegistered|null, array{participant: string, email: string, name: string, tournament: string, slug: string}|null} $outcome */
        $outcome = $this->lock->forConfirmationToken(
            $token,
            fn (): array => $this->confirmUnderLock($token, $now),
        );

        [$result, $registered, $lapsed] = $outcome;

        // Mailed once the confirmation is committed
        if ($registered !== null) {
            event($registered);
        }

        // A held seat that lapsed here is told exactly as the expiry command would; the expiry is
        // committed, so a failing email is logged and the page still answers
        if ($lapsed !== null) {
            try {
                GuestRegistrationExpiredNotification::sendTo($lapsed['email'], $lapsed['name'], $lapsed['tournament'], $lapsed['slug']);
            } catch (Throwable $e) {
                Log::warning('tournaments: guest registration expiry email failed', [
                    'participant_id' => $lapsed['participant'],
                    'exception' => $e,
                ]);
            }
        }

        return $result;
    }

    public function findGuestConfirmation(string $token): ?array
    {
        $participant = $this->participantRepository->findByConfirmationToken($token);

        if (! $participant instanceof Participant) {
            return null;
        }

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if (! $tournament instanceof Tournament) {
            return null;
        }

        $expiresAt = $participant->confirmationExpiresAt();

        $state = match (true) {
            ! $participant->isActive() => 'invalid',
            $participant->emailConfirmedAt() instanceof DateTimeImmutable => 'already_confirmed',
            $this->isOverdue($participant, $this->now()) => 'invalid',
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

    public function registerByOrganization(RegisterParticipantDTO $dto, ParticipantStatus $status = ParticipantStatus::Registered): ParticipantResponseDTO
    {
        $userId = $dto->userId;
        $guestName = $userId === null ? $dto->guestName : null;

        if ($userId === null && ($guestName === null || trim($guestName) === '')) {
            throw new InvalidArgumentException('A guest added by the organisation needs a name.');
        }

        $guestEmail = $userId === null && $dto->guestEmail !== null && trim($dto->guestEmail) !== ''
            ? mb_strtolower(trim($dto->guestEmail))
            : null;
        $now = $this->clock->now();

        return $this->lock->forTournament(
            $dto->tournamentId,
            function () use ($dto, $status, $userId, $guestName, $guestEmail, $now): ParticipantResponseDTO {
                $tournament = $this->tournamentRepository->find(TournamentId::fromString($dto->tournamentId));

                if (! $tournament instanceof Tournament) {
                    throw TournamentNotFoundException::withId($dto->tournamentId);
                }

                if ($userId !== null) {
                    $existing = $this->participantRepository->findByUserAndTournament($userId, $dto->tournamentId);

                    // One row per user and tournament, whatever its status
                    if ($existing instanceof Participant) {
                        $this->rejectDisqualified($existing);

                        throw AlreadyRegisteredException::userAlreadyRegistered($dto->tournamentId, $userId);
                    }
                } elseif ($guestEmail !== null) {
                    $existing = $this->participantRepository->findByGuestEmailAndTournament($guestEmail, $dto->tournamentId);

                    if ($existing instanceof Participant) {
                        $this->rejectDisqualified($existing);

                        if ($existing->isActive()) {
                            throw AlreadyRegisteredException::guestWithEmailAlreadyRegistered($dto->tournamentId, $existing->id()->value);
                        }
                    }
                }

                if ($status->isActive()) {
                    $this->ensureCapacity($tournament);
                }

                // The organisation vouches for the email: confirmed, with no confirmation link or fingerprint
                $participant = new Participant(
                    id: ParticipantId::generate(),
                    tournamentId: $dto->tournamentId,
                    status: $status,
                    userId: $userId,
                    guestName: $guestName,
                    guestEmail: $guestEmail,
                    cancellationToken: $userId === null ? bin2hex(random_bytes(32)) : null,
                    seed: $dto->seed,
                    hasReceivedBye: false,
                    registeredAt: $now,
                    checkedInAt: $status === ParticipantStatus::CheckedIn ? $now : null,
                    emailConfirmedAt: $now,
                );

                $this->participantRepository->save($participant);

                return $this->createResponseDTO($participant);
            },
        );
    }

    public function confirmEmailByOrganization(string $participantId): ParticipantResponseDTO
    {
        // Vouching for the email is the whole action
        return $this->actAsOrganization($participantId, takesSeat: true, act: static function (): void {});
    }

    public function confirm(string $participantId): ParticipantResponseDTO
    {
        return $this->actAsOrganization(
            $participantId,
            takesSeat: true,
            act: static function (Participant $participant): void {
                $participant->confirm();
            },
        );
    }

    public function checkIn(string $participantId): ParticipantResponseDTO
    {
        return $this->actAsOrganization(
            $participantId,
            takesSeat: true,
            act: static function (Participant $participant, CarbonImmutable $now): void {
                $participant->checkInByOrganization($now->toDateTimeImmutable());
            },
            announce: false,
        );
    }

    public function withdraw(string $participantId): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if (! $participant instanceof Participant) {
            throw ParticipantNotFoundException::withId($participantId);
        }

        $this->ensureCanWithdraw($participant);

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if (! $tournament instanceof Tournament) {
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

        if (! $participant instanceof Participant) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function withdrawByToken(string $token): ParticipantResponseDTO
    {
        $participant = $this->participantRepository->findByCancellationToken($token);

        if (! $participant instanceof Participant) {
            throw ParticipantNotFoundException::byToken();
        }

        $this->ensureCanWithdraw($participant);

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if (! $tournament instanceof Tournament) {
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
        // Read before acting: the organisation's action confirms a pending guest's email, but an
        // address nobody confirmed is never mailed
        $wasAwaitingConfirmation = $this->participantRepository
            ->find(ParticipantId::fromString($participantId))
            ?->isAwaitingEmailConfirmation() ?? false;

        $response = $this->actAsOrganization(
            $participantId,
            takesSeat: false,
            act: static function (Participant $participant): void {
                $participant->disqualifyByOrganization();
            },
            announce: false,
        );

        // Mailed once the change is committed
        if (! $wasAwaitingConfirmation) {
            event(ParticipantDisqualified::create($response->id, $response->tournamentId, $response->userId, $reason));
        }

        return $response;
    }

    /**
     * @param  array<string>  $participantIds
     * @return array<ParticipantResponseDTO>
     */
    public function bulkCheckIn(array $participantIds): array
    {
        $results = [];

        foreach ($participantIds as $participantId) {
            if (! $this->participantRepository->find(ParticipantId::fromString($participantId)) instanceof Participant) {
                continue;
            }

            $results[] = $this->checkIn($participantId);
        }

        return $results;
    }

    public function find(string $participantId): ?ParticipantResponseDTO
    {
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        if (! $participant instanceof Participant) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function findByUserAndTournament(string $userId, string $tournamentId): ?ParticipantResponseDTO
    {
        $participant = $this->participantRepository->findByUserAndTournament($userId, $tournamentId);

        if (! $participant instanceof Participant) {
            return null;
        }

        return $this->createResponseDTO($participant);
    }

    public function canCheckIn(ParticipantStatus $status, bool $requiresManualConfirmation): bool
    {
        return $requiresManualConfirmation
            ? $status === ParticipantStatus::Confirmed
            : in_array($status, [ParticipantStatus::Registered, ParticipantStatus::Confirmed], true);
    }

    public function checkInByEmail(string $tournamentId, string $email): ParticipantResponseDTO
    {
        return $this->selfCheckIn(
            $tournamentId,
            fn (): ?Participant => $this->participantRepository->findActiveByEmailAndTournament($email, $tournamentId),
            static fn (): ParticipantNotFoundException => ParticipantNotFoundException::byEmail($tournamentId),
        );
    }

    public function checkInByUser(string $tournamentId, string $userId): ParticipantResponseDTO
    {
        return $this->selfCheckIn(
            $tournamentId,
            function () use ($tournamentId, $userId): ?Participant {
                $participant = $this->participantRepository->findByUserAndTournament($userId, $tournamentId);

                return $participant instanceof Participant && $participant->isActive() ? $participant : null;
            },
            static fn (): ParticipantNotFoundException => ParticipantNotFoundException::forUserInTournament($userId, $tournamentId),
        );
    }

    /**
     * The participant is looked up and checked in under the tournament lock, so a second submit
     * or a concurrent panel action sees the committed state.
     *
     * @param  Closure(): ?Participant  $find  the active registration checking in
     * @param  Closure(): ParticipantNotFoundException  $notFound
     */
    private function selfCheckIn(string $tournamentId, Closure $find, Closure $notFound): ParticipantResponseDTO
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($tournamentId));

        if (! $tournament instanceof Tournament) {
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

        return $this->lock->forTournament(
            $tournamentId,
            function () use ($tournament, $tournamentId, $find, $notFound): ParticipantResponseDTO {
                $participant = $find();

                if ($participant === null) {
                    throw $notFound();
                }

                // Knowing the email of an unconfirmed guest registration proves nothing
                if ($participant->isAwaitingEmailConfirmation()) {
                    throw CheckInNotAllowedException::emailNotConfirmed($tournamentId);
                }

                if ($participant->status() === ParticipantStatus::CheckedIn) {
                    throw AlreadyCheckedInException::forParticipant($participant->id()->value);
                }

                if (! $this->canCheckIn($participant->status(), $tournament->requiresManualConfirmation())) {
                    throw CheckInNotAllowedException::registrationNotConfirmed($tournamentId);
                }

                // The same transition as the panel's check-in, now that canCheckIn() allowed it
                $participant->checkInByOrganization($this->clock->now());
                $this->participantRepository->save($participant);

                return $this->createResponseDTO($participant);
            },
        );
    }

    private function validateCheckInWindow(Tournament $tournament): void
    {
        // If tournament is already in progress or finished, check-in is closed
        if (in_array($tournament->status(), [TournamentStatus::InProgress, TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            throw CheckInWindowClosedException::tournamentStarted($tournament->id()->value);
        }

        $eventStartDate = $tournament->eventStartsAt();

        if (! $eventStartDate instanceof DateTimeImmutable) {
            throw CheckInWindowClosedException::alreadyClosed($tournament->id()->value);
        }

        $windowOpensAt = Tournament::checkInOpensAt($eventStartDate, $tournament->checkInStartsBefore());

        $now = $this->clock->now();

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
            ! $tournament->eventStartsAt() instanceof DateTimeImmutable => TournamentNotOpenException::alreadyClosed($tournamentId),
            $tournament->hasEventStarted($now) => TournamentNotOpenException::eventStarted($tournamentId),
            $opensAt instanceof DateTimeImmutable && $now < $opensAt => TournamentNotOpenException::notYetOpen($tournamentId),
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
        if ($tournament->canWithdraw($this->clock->now())) {
            return;
        }

        throw match (true) {
            $tournament->status() === TournamentStatus::InProgress => CannotWithdrawException::tournamentInProgress($participantId),
            $tournament->status() === TournamentStatus::Finished => CannotWithdrawException::tournamentFinished($participantId),
            $tournament->status() === TournamentStatus::Cancelled => CannotWithdrawException::tournamentCancelled($participantId),
            ! $tournament->eventStartsAt() instanceof DateTimeImmutable => CannotWithdrawException::eventStartUnknown($participantId),
            default => CannotWithdrawException::tournamentStarted($participantId),
        };
    }

    /**
     * The current instant as Carbon, the type the registration rules work with.
     */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now());
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

    /**
     * An organisation action on a registration, under the tournament lock. Acting on a guest
     * registration still awaiting confirmation vouches for its email first: it no longer expires,
     * and one waiting without a seat takes one when the action needs it. The guest then gets the
     * usual registration email, unless the action is one where it would be of no use; the
     * organisation is never told, since it acted itself.
     *
     * @param  Closure(Participant, CarbonImmutable): void  $act
     *
     * @throws ParticipantNotFoundException
     * @throws TournamentFullException
     */
    private function actAsOrganization(string $participantId, bool $takesSeat, Closure $act, bool $announce = true): ParticipantResponseDTO
    {
        $now = $this->now();

        /** @var array{ParticipantResponseDTO, ParticipantRegistered|null} $outcome */
        $outcome = $this->lock->forParticipant($participantId, function () use ($participantId, $takesSeat, $act, $announce, $now): array {
            $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

            if (! $participant instanceof Participant) {
                throw ParticipantNotFoundException::withId($participantId);
            }

            $endorsed = $participant->isAwaitingEmailConfirmation();

            if ($endorsed && $takesSeat && ! $participant->holdsSeat()) {
                $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

                if (! $tournament instanceof Tournament) {
                    throw TournamentNotFoundException::withId($participant->tournamentId());
                }

                $this->ensureCapacity($tournament);
            }

            if ($endorsed) {
                $participant->markEmailConfirmed($now->toDateTimeImmutable());
            }

            $act($participant, $now);
            $this->participantRepository->save($participant);

            return [
                $this->createResponseDTO($participant),
                $endorsed && $announce ? $this->guestRegistered($participant, notifyOrganizer: false) : null,
            ];
        });

        [$response, $registered] = $outcome;

        // Mailed once the change is committed
        if ($registered !== null) {
            event($registered);
        }

        return $response;
    }

    private function guestRegistered(Participant $participant, bool $notifyOrganizer): ParticipantRegistered
    {
        return ParticipantRegistered::create(
            participantId: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: null,
            guestEmail: $participant->guestEmail(),
            isGuest: true,
            cancellationToken: $participant->cancellationToken(),
            notifyOrganizer: $notifyOrganizer,
        );
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

        if (! $tournament instanceof Tournament) {
            throw TournamentNotFoundException::withId($dto->tournamentId);
        }

        if (! $tournament->isRegistrationOpen($now)) {
            throw $this->registrationClosedReason($tournament, $now);
        }

        $existingParticipant = $this->participantRepository->findByUserAndTournament($userId, $dto->tournamentId);
        if ($existingParticipant instanceof Participant) {
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
        if ($guestRegistration instanceof Participant) {
            return $this->takeOverGuestRegistration($tournament, $guestRegistration, $existingParticipant, $userId, $dto, $now);
        }

        $this->ensureCapacity($tournament);

        if ($existingParticipant instanceof Participant) {
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

        if (! $guestRegistration instanceof Participant || ! $guestRegistration->isActive()) {
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

        if ($ownRegistration instanceof Participant) {
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

        if (! $tournament instanceof Tournament) {
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

        if ($existing instanceof Participant) {
            $this->rejectDisqualified($existing);
            $this->expireIfOverdue($existing, $now);

            if ($existing->isActive()) {
                if (! $existing->isAwaitingEmailConfirmation()) {
                    throw AlreadyRegisteredException::guestWithEmailAlreadyRegistered($dto->tournamentId, $existing->id()->value);
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

        if ($existing instanceof Participant) {
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
            && $expiresAt instanceof DateTimeImmutable
            && $expiresAt <= $now;
    }

    /**
     * The confirmation itself, under the tournament lock. A registration still in the state the
     * form left it lapses instead when registration closed, its deadline passed, or it waits
     * without a seat in a full tournament.
     *
     * The third element is the expiry email to send once the lock is released, for a held seat that lapsed.
     *
     * @return array{GuestConfirmationResultDTO, ParticipantRegistered|null, array{participant: string, email: string, name: string, tournament: string, slug: string}|null}
     */
    private function confirmUnderLock(string $token, CarbonImmutable $now): array
    {
        $participant = $this->participantRepository->findByConfirmationToken($token);
        $tournament = $participant instanceof Participant
            ? $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()))
            : null;

        if (! $participant instanceof Participant || ! $tournament instanceof Tournament) {
            return [new GuestConfirmationResultDTO(GuestConfirmationOutcome::Invalid, null, null), null, null];
        }

        $answer = static fn (GuestConfirmationOutcome $outcome): GuestConfirmationResultDTO => new GuestConfirmationResultDTO(
            $outcome,
            $tournament->id()->value,
            $tournament->slug(),
        );

        if (! $participant->isActive()) {
            return [$answer(GuestConfirmationOutcome::Invalid), null, null];
        }

        if (! $participant->isAwaitingEmailConfirmation()) {
            return [$answer(GuestConfirmationOutcome::AlreadyConfirmed), null, null];
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
                $heldSeat = $participant->confirmationMode() === GuestConfirmationMode::Hold;
                $participant->expire();
                $this->participantRepository->save($participant);

                $email = $participant->guestEmail();

                return [
                    $answer($lapse),
                    null,
                    $heldSeat && $email !== null
                        ? ['participant' => $participant->id()->value, 'email' => $email, 'name' => $participant->guestName() ?? $email, 'tournament' => $tournament->name(), 'slug' => $tournament->slug()]
                        : null,
                ];
            }
        }

        $participant->markEmailConfirmed($now->toDateTimeImmutable());
        $this->participantRepository->save($participant);

        return [
            $answer(GuestConfirmationOutcome::Confirmed),
            $this->guestRegistered($participant, notifyOrganizer: true),
            null,
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
        if ($registration instanceof Participant && $registration->isActive()) {
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
            && (! $sentAt instanceof DateTimeImmutable || $sentAt <= $now->subMinutes(self::RESEND_MARGIN_MINUTES));

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
