<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\StandingsResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class TournamentQueryService implements TournamentQueryServiceInterface
{
    private const string REPORT_RESULTS_PERMISSION = 'tournaments:tournaments.report_results';

    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private StandingRepositoryInterface $standingRepository,
        private RoundRepositoryInterface $roundRepository,
        private MatchRepositoryInterface $matchRepository,
        private UserDataProviderInterface $userDataProvider,
        private Clock $clock,
        private AuthorizationServiceInterface $authorization,
    ) {}

    public function find(string $tournamentId): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->find(
            TournamentId::fromString($tournamentId)
        );

        if (! $tournament instanceof Tournament) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournamentId);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount, $this->clock->now());
    }

    public function findBySlug(string $slug): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->findBySlug($slug);

        if (! $tournament instanceof Tournament) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournament->id()->value);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount, $this->clock->now());
    }

    public function findByEventId(string $eventId): ?TournamentResponseDTO
    {
        $tournament = $this->tournamentRepository->findByEventId($eventId);

        if (! $tournament instanceof Tournament) {
            return null;
        }

        $participantCount = $this->participantRepository->countActiveByTournament($tournament->id()->value);

        return TournamentResponseDTO::fromEntity($tournament, $participantCount, $this->clock->now());
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
        // Guests who have not confirmed their email are not shown publicly
        $participants = array_values(array_filter(
            $this->participantRepository->findByTournament($tournamentId),
            static fn ($participant): bool => ! $participant->isGuest() || $participant->emailConfirmedAt() !== null,
        ));

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
            TournamentId::fromString($tournamentId)
        );

        if (! $tournament instanceof Tournament) {
            return false;
        }

        if (! $tournament->isRegistrationOpen($this->clock->now())) {
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
        if ($existingParticipant instanceof Participant) {
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

    public function canUserReportResult(string $matchId, string $userId): bool
    {
        // Whoever records results from the panel may report any match (admins hold it implicitly)
        $user = UserModel::query()->find($userId);
        if ($user instanceof UserModel && $this->authorization->can($user, self::REPORT_RESULTS_PERMISSION)) {
            return true;
        }

        $match = $this->matchRepository->find(MatchId::fromString($matchId));
        if (! $match instanceof TournamentMatch) {
            return false;
        }

        $round = $this->roundRepository->find(
            RoundId::fromString($match->roundId())
        );
        if (! $round instanceof Round) {
            return false;
        }

        $tournament = $this->tournamentRepository->find(
            TournamentId::fromString($round->tournamentId())
        );
        if (! $tournament instanceof Tournament) {
            return false;
        }

        $resultReporting = $tournament->resultReporting();

        if ($resultReporting === ResultReporting::AdminOnly) {
            return false;
        }

        $player1Participant = $this->participantRepository->find(
            ParticipantId::fromString($match->player1Id())
        );
        $player2Participant = $match->player2Id() !== null
            ? $this->participantRepository->find(
                ParticipantId::fromString($match->player2Id())
            )
            : null;

        $isPlayer1 = $player1Participant instanceof Participant && $player1Participant->userId() === $userId;
        $isPlayer2 = $player2Participant instanceof Participant && $player2Participant->userId() === $userId;

        return $isPlayer1 || $isPlayer2;
    }

    public function canUserConfirmResult(string $matchId, string $userId): bool
    {
        $match = $this->matchRepository->find(MatchId::fromString($matchId));
        if (! $match instanceof TournamentMatch) {
            return false;
        }

        if (! $match->reportedAt() instanceof \DateTimeImmutable) {
            return false;
        }

        if ($match->confirmedAt() instanceof \DateTimeImmutable) {
            return false;
        }

        $round = $this->roundRepository->find(
            RoundId::fromString($match->roundId())
        );
        if (! $round instanceof Round) {
            return false;
        }

        $tournament = $this->tournamentRepository->find(
            TournamentId::fromString($round->tournamentId())
        );
        if (! $tournament instanceof Tournament) {
            return false;
        }

        if ($tournament->resultReporting() !== ResultReporting::PlayersWithConfirmation) {
            return false;
        }

        $player1Participant = $this->participantRepository->find(
            ParticipantId::fromString($match->player1Id())
        );
        $player2Participant = $match->player2Id() !== null
            ? $this->participantRepository->find(
                ParticipantId::fromString($match->player2Id())
            )
            : null;

        $reporterParticipantId = null;
        if ($player1Participant instanceof Participant && $player1Participant->userId() === $match->reportedById()) {
            $reporterParticipantId = $player1Participant->id()->value;
        } elseif ($player2Participant instanceof Participant && $player2Participant->userId() === $match->reportedById()) {
            $reporterParticipantId = $player2Participant->id()->value;
        }

        if ($reporterParticipantId === null) {
            return false;
        }

        $isPlayer1 = $player1Participant instanceof Participant && $player1Participant->userId() === $userId;
        $isPlayer2 = $player2Participant instanceof Participant && $player2Participant->userId() === $userId;

        if (! $isPlayer1 && ! $isPlayer2) {
            return false;
        }

        $userParticipantId = $isPlayer1 ? $player1Participant->id()->value : $player2Participant->id()->value;

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

        /** @var list<string> $ids */
        $ids = $this->buildPublishedQuery($statusFilter)
            ->orderByRaw($this->getStatusPriorityOrderSql())
            ->orderBy('started_at', 'desc')
            ->orderBy('registration_opens_at', 'asc')
            ->orderBy('completed_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->pluck('id')
            ->all();

        $now = $this->clock->now();

        // Through the entity, so the listing gets the same registration verdict as the pages
        return array_map(
            fn (Tournament $tournament): TournamentResponseDTO => TournamentResponseDTO::fromEntity(
                $tournament,
                $this->participantRepository->countActiveByTournament($tournament->id()->value),
                $now,
            ),
            $this->tournamentRepository->findByIds($ids),
        );
    }

    public function getPublishedTotal(?array $statusFilter = null): int
    {
        return $this->buildPublishedQuery($statusFilter)->count();
    }

    /**
     * @param  array<string>|null  $statusFilter
     * @return Builder<TournamentModel>
     */
    private function buildPublishedQuery(?array $statusFilter = null): Builder
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

    /**
     * @return literal-string
     */
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
}
