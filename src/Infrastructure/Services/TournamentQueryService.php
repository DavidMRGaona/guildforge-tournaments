<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\StandingsResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class TournamentQueryService implements TournamentQueryServiceInterface
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private StandingRepositoryInterface $standingRepository,
        private RoundRepositoryInterface $roundRepository,
        private MatchRepositoryInterface $matchRepository,
        private UserDataProviderInterface $userDataProvider,
    ) {}

    public function find(string $tournamentId): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\TournamentId::fromString($tournamentId)
        );

        if ($tournament === null) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournamentId);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount);
    }

    public function findBySlug(string $slug): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->findBySlug($slug);

        if ($tournament === null) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournament->id()->value);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount);
    }

    public function findByEventId(string $eventId): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->findByEventId($eventId);

        if ($tournament === null) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournament->id()->value);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount);
    }

    /**
     * @return array<StandingsResponseDTO>
     */
    public function getStandings(string $tournamentId): array
    {
        $standings = $this->standingRepository->findByTournamentOrderedByRank($tournamentId);

        if ($standings === []) {
            return [];
        }

        $participantNames = $this->getParticipantNamesMap($tournamentId);

        return array_map(
            fn ($standing) => StandingsResponseDTO::fromEntity(
                $standing,
                $participantNames[$standing->participantId()] ?? __('tournaments::messages.participants.unknown')
            ),
            $standings
        );
    }

    /**
     * @return array<ParticipantResponseDTO>
     */
    public function getParticipants(string $tournamentId): array
    {
        $participants = $this->participantRepository->findByTournament($tournamentId);

        if ($participants === []) {
            return [];
        }

        $userIds = array_values(array_filter(
            array_map(fn ($p) => $p->userId(), $participants)
        ));

        $usersInfo = $userIds !== []
            ? $this->userDataProvider->getUsersInfo($userIds)
            : [];

        return array_map(
            fn ($participant) => ParticipantResponseDTO::fromEntity(
                $participant,
                $participant->userId() !== null ? ($usersInfo[$participant->userId()]['name'] ?? null) : null,
                $participant->userId() !== null ? ($usersInfo[$participant->userId()]['email'] ?? null) : null,
            ),
            $participants
        );
    }

    public function canUserRegister(string $tournamentId, ?string $userId, array $userRoles = []): bool
    {
        $tournament = $this->tournamentRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\TournamentId::fromString($tournamentId)
        );

        if ($tournament === null) {
            return false;
        }

        if ($tournament->status() !== TournamentStatus::RegistrationOpen) {
            return false;
        }

        if ($tournament->maxParticipants() !== null) {
            $currentCount = $this->participantRepository->countActiveByTournament($tournamentId);
            if ($currentCount >= $tournament->maxParticipants()) {
                return false;
            }
        }

        if ($userId === null) {
            return $tournament->allowGuests();
        }

        $existingParticipant = $this->participantRepository->findByUserAndTournament($userId, $tournamentId);
        if ($existingParticipant !== null) {
            $activeStatuses = [
                ParticipantStatus::Registered,
                ParticipantStatus::Confirmed,
                ParticipantStatus::CheckedIn,
            ];
            if (in_array($existingParticipant->status(), $activeStatuses, true)) {
                return false;
            }
        }

        $allowedRoles = $tournament->allowedRoles();
        if ($allowedRoles !== []) {
            $hasAllowedRole = array_intersect($userRoles, $allowedRoles) !== [];
            if (! $hasAllowedRole) {
                return false;
            }
        }

        return true;
    }

    public function canUserReportResult(string $matchId, string $userId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            return true;
        }

        $match = $this->matchRepository->find(MatchId::fromString($matchId));
        if ($match === null) {
            return false;
        }

        $round = $this->roundRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\RoundId::fromString($match->roundId())
        );
        if ($round === null) {
            return false;
        }

        $tournament = $this->tournamentRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\TournamentId::fromString($round->tournamentId())
        );
        if ($tournament === null) {
            return false;
        }

        $resultReporting = $tournament->resultReporting();

        if ($resultReporting === \Modules\Tournaments\Domain\Enums\ResultReporting::AdminOnly) {
            return false;
        }

        $player1Participant = $this->participantRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\ParticipantId::fromString($match->player1Id())
        );
        $player2Participant = $match->player2Id() !== null
            ? $this->participantRepository->find(
                \Modules\Tournaments\Domain\ValueObjects\ParticipantId::fromString($match->player2Id())
            )
            : null;

        $isPlayer1 = $player1Participant !== null && $player1Participant->userId() === $userId;
        $isPlayer2 = $player2Participant !== null && $player2Participant->userId() === $userId;

        return $isPlayer1 || $isPlayer2;
    }

    public function canUserConfirmResult(string $matchId, string $userId): bool
    {
        $match = $this->matchRepository->find(MatchId::fromString($matchId));
        if ($match === null) {
            return false;
        }

        if ($match->reportedAt() === null) {
            return false;
        }

        if ($match->confirmedAt() !== null) {
            return false;
        }

        $round = $this->roundRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\RoundId::fromString($match->roundId())
        );
        if ($round === null) {
            return false;
        }

        $tournament = $this->tournamentRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\TournamentId::fromString($round->tournamentId())
        );
        if ($tournament === null) {
            return false;
        }

        if ($tournament->resultReporting() !== \Modules\Tournaments\Domain\Enums\ResultReporting::PlayersWithConfirmation) {
            return false;
        }

        $player1Participant = $this->participantRepository->find(
            \Modules\Tournaments\Domain\ValueObjects\ParticipantId::fromString($match->player1Id())
        );
        $player2Participant = $match->player2Id() !== null
            ? $this->participantRepository->find(
                \Modules\Tournaments\Domain\ValueObjects\ParticipantId::fromString($match->player2Id())
            )
            : null;

        $reporterParticipantId = null;
        if ($player1Participant !== null && $player1Participant->userId() === $match->reportedById()) {
            $reporterParticipantId = $player1Participant->id()->value;
        } elseif ($player2Participant !== null && $player2Participant->userId() === $match->reportedById()) {
            $reporterParticipantId = $player2Participant->id()->value;
        }

        if ($reporterParticipantId === null) {
            return false;
        }

        $isPlayer1 = $player1Participant !== null && $player1Participant->userId() === $userId;
        $isPlayer2 = $player2Participant !== null && $player2Participant->userId() === $userId;

        if (! $isPlayer1 && ! $isPlayer2) {
            return false;
        }

        $userParticipantId = $isPlayer1 ? $player1Participant->id()->value : $player2Participant?->id()->value;

        return $userParticipantId !== $reporterParticipantId;
    }

    public function getParticipantCount(string $tournamentId): int
    {
        return $this->participantRepository->countByTournament($tournamentId);
    }

    public function getActiveParticipantCount(string $tournamentId): int
    {
        return $this->participantRepository->countActiveByTournament($tournamentId);
    }

    /**
     * @return array<string, string>
     */
    private function getParticipantNamesMap(string $tournamentId): array
    {
        $participants = $this->participantRepository->findByTournament($tournamentId);

        $userIds = array_values(array_filter(
            array_map(fn ($p) => $p->userId(), $participants)
        ));

        $usersInfo = $userIds !== []
            ? $this->userDataProvider->getUsersInfo($userIds)
            : [];

        $namesMap = [];
        foreach ($participants as $participant) {
            if ($participant->userId() !== null && isset($usersInfo[$participant->userId()])) {
                $namesMap[$participant->id()->value] = $usersInfo[$participant->userId()]['name'];
            } elseif ($participant->guestName() !== null) {
                $namesMap[$participant->id()->value] = $participant->guestName();
            } else {
                $namesMap[$participant->id()->value] = __('tournaments::messages.participants.unknown');
            }
        }

        return $namesMap;
    }

    /**
     * @return array<TournamentResponseDTO>
     */
    public function getPublishedPaginated(int $page = 1, int $perPage = 12, ?array $statusFilter = null): array
    {
        $offset = ($page - 1) * $perPage;

        $query = $this->buildPublishedQuery($statusFilter);

        $tournaments = $query
            ->orderByRaw($this->getStatusPriorityOrderSql())
            ->orderBy('started_at', 'desc')
            ->orderBy('registration_opens_at', 'asc')
            ->orderBy('completed_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return $tournaments->map(function (TournamentModel $model): TournamentResponseDTO {
            $participantCount = $this->participantRepository->countActiveByTournament($model->id);

            return new TournamentResponseDTO(
                id: $model->id,
                eventId: $model->event_id,
                name: $model->name,
                slug: $model->slug,
                description: $model->description,
                imagePublicId: $model->image_public_id,
                status: $model->status,
                maxRounds: $model->max_rounds,
                currentRound: $model->current_round ?? 0,
                maxParticipants: $model->max_participants,
                minParticipants: $model->min_participants ?? 2,
                participantCount: $participantCount,
                scoreWeights: [],
                tiebreakers: [],
                allowGuests: $model->allow_guests ?? false,
                requiresManualConfirmation: $model->requires_manual_confirmation ?? false,
                allowedRoles: $model->allowed_roles ?? [],
                resultReporting: $model->result_reporting,
                requiresCheckIn: $model->requires_check_in ?? false,
                checkInStartsBefore: $model->check_in_starts_before,
                registrationOpensAt: $model->registration_opens_at?->toImmutable(),
                registrationClosesAt: $model->registration_closes_at?->toImmutable(),
                startedAt: $model->started_at?->toImmutable(),
                completedAt: $model->completed_at?->toImmutable(),
                createdAt: $model->created_at->toImmutable(),
                updatedAt: $model->updated_at->toImmutable(),
                showParticipants: $model->show_participants ?? true,
                notificationEmail: $model->notification_email ?? '',
                selfCheckInAllowed: $model->self_check_in_allowed ?? false,
            );
        })->all();
    }

    public function getPublishedTotal(?array $statusFilter = null): int
    {
        return $this->buildPublishedQuery($statusFilter)->count();
    }

    /**
     * @param  array<string>|null  $statusFilter
     * @return \Illuminate\Database\Eloquent\Builder<TournamentModel>
     */
    private function buildPublishedQuery(?array $statusFilter = null): \Illuminate\Database\Eloquent\Builder
    {
        $excludedStatuses = [
            TournamentStatus::Draft->value,
            TournamentStatus::Cancelled->value,
        ];

        $query = TournamentModel::query()
            ->whereNotIn('status', $excludedStatuses);

        if ($statusFilter !== null && $statusFilter !== []) {
            $query->whereIn('status', $statusFilter);
        }

        return $query;
    }

    private function getStatusPriorityOrderSql(): string
    {
        // Priority: in_progress (1) → registration_open/closed (2) → finished (3)
        return "CASE
            WHEN status = 'in_progress' THEN 1
            WHEN status IN ('registration_open', 'registration_closed') THEN 2
            WHEN status = 'finished' THEN 3
            ELSE 4
        END";
    }

    public function getEventStartDate(string $tournamentId): ?DateTimeImmutable
    {
        $tournament = TournamentModel::query()
            ->where('id', $tournamentId)
            ->first(['event_id']);

        if ($tournament === null) {
            return null;
        }

        $event = EventModel::query()
            ->where('id', $tournament->event_id)
            ->first(['start_date']);

        if ($event === null || $event->start_date === null) {
            return null;
        }

        return $event->start_date->toImmutable();
    }
}
