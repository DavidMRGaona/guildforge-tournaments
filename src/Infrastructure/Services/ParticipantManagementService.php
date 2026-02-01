<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
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
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class ParticipantManagementService implements ParticipantManagementServiceInterface
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
    ) {}

    public function register(RegisterParticipantDTO $dto): ParticipantResponseDTO
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($dto->tournamentId));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($dto->tournamentId);
        }

        if ($tournament->status() !== TournamentStatus::RegistrationOpen) {
            throw TournamentNotOpenException::withId($dto->tournamentId);
        }

        if ($tournament->maxParticipants() !== null) {
            $currentCount = $this->participantRepository->countActiveByTournament($dto->tournamentId);
            if ($currentCount >= $tournament->maxParticipants()) {
                throw TournamentFullException::withId($dto->tournamentId);
            }
        }

        if ($dto->isGuest()) {
            if (! $tournament->allowGuests()) {
                throw GuestRegistrationNotAllowedException::forTournament($dto->tournamentId);
            }

            if ($dto->guestEmail !== null) {
                $existingGuest = $this->participantRepository->findByGuestEmailAndTournament(
                    $dto->guestEmail,
                    $dto->tournamentId
                );
                if ($existingGuest !== null) {
                    if ($this->isActiveParticipant($existingGuest)) {
                        throw AlreadyRegisteredException::forGuest($dto->guestEmail, $dto->tournamentId);
                    }
                    if ($existingGuest->status() === ParticipantStatus::Withdrawn) {
                        return $this->reactivateParticipant($existingGuest);
                    }
                }
            }
        } else {
            $existingParticipant = $this->participantRepository->findByUserAndTournament(
                $dto->userId,
                $dto->tournamentId
            );
            if ($existingParticipant !== null) {
                if ($this->isActiveParticipant($existingParticipant)) {
                    throw AlreadyRegisteredException::userAlreadyRegistered($dto->tournamentId, $dto->userId);
                }
                if ($existingParticipant->status() === ParticipantStatus::Withdrawn) {
                    return $this->reactivateParticipant($existingParticipant);
                }
            }

            $allowedRoles = $tournament->allowedRoles();
            if ($allowedRoles !== [] && ! $this->userDataProvider->userHasAnyRole($dto->userId, $allowedRoles)) {
                throw UserNotAllowedToRegisterException::missingRole($dto->userId);
            }
        }

        // Generate cancellation token for guests
        $cancellationToken = $dto->isGuest() ? bin2hex(random_bytes(32)) : null;

        $participant = new Participant(
            id: ParticipantId::generate(),
            tournamentId: $dto->tournamentId,
            status: ParticipantStatus::Registered,
            userId: $dto->userId,
            guestName: $dto->guestName,
            guestEmail: $dto->guestEmail,
            cancellationToken: $cancellationToken,
            seed: $dto->seed,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable,
        );

        $this->participantRepository->save($participant);

        // Dispatch registration event for notifications
        event(ParticipantRegistered::create(
            participantId: $participant->id()->value,
            tournamentId: $dto->tournamentId,
            userId: $dto->userId,
            guestEmail: $dto->guestEmail,
            isGuest: $dto->isGuest(),
        ));

        $userInfo = $dto->userId !== null
            ? $this->userDataProvider->getUserInfo($dto->userId)
            : null;

        return ParticipantResponseDTO::fromEntity(
            $participant,
            $userInfo['name'] ?? null,
            $userInfo['email'] ?? null
        );
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

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($participant->tournamentId());
        }

        if ($tournament->status() === TournamentStatus::InProgress) {
            throw CannotWithdrawException::tournamentInProgress($participantId);
        }

        if ($tournament->status() === TournamentStatus::Finished) {
            throw CannotWithdrawException::tournamentFinished($participantId);
        }

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

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($participant->tournamentId());
        }

        if ($tournament->status() === TournamentStatus::InProgress) {
            throw CannotWithdrawException::tournamentInProgress($participant->id()->value);
        }

        if ($tournament->status() === TournamentStatus::Finished) {
            throw CannotWithdrawException::tournamentFinished($participant->id()->value);
        }

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

    private function validateCheckInWindow(\Modules\Tournaments\Domain\Entities\Tournament $tournament): void
    {
        // If tournament is already in progress or finished, check-in is closed
        if (in_array($tournament->status(), [TournamentStatus::InProgress, TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            throw CheckInWindowClosedException::tournamentStarted($tournament->id()->value);
        }

        // Get event start date
        $tournamentModel = TournamentModel::query()
            ->where('id', $tournament->id()->value)
            ->first(['event_id']);

        if ($tournamentModel === null) {
            throw TournamentNotFoundException::withId($tournament->id()->value);
        }

        $event = EventModel::query()
            ->where('id', $tournamentModel->event_id)
            ->first(['start_date']);

        if ($event === null || $event->start_date === null) {
            throw CheckInWindowClosedException::alreadyClosed($tournament->id()->value);
        }

        $eventStartDate = $event->start_date->toDateTimeImmutable();
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
