<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\DTOs\Response\MatchHistoryResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchReportResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\PlayerMatchResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\MatchReportRules;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

final readonly class MatchQueryService implements MatchQueryServiceInterface
{
    public function __construct(
        private MatchRepositoryInterface $matchRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
        private MatchReportRepositoryInterface $reports,
        private MatchHistoryRepositoryInterface $history,
        private RoundRepositoryInterface $rounds,
        private TournamentRepositoryInterface $tournaments,
        private MatchReportRules $rules,
    ) {}

    /**
     * @return array<MatchResponseDTO>
     */
    public function getMatchesForRound(string $roundId): array
    {
        $matches = $this->matchRepository->findByRound($roundId);

        if ($matches === []) {
            return [];
        }

        $participantNames = $this->buildParticipantNamesCache($matches);
        $reports = $this->reports->findByRound($roundId);

        return array_map(
            fn ($match) => MatchResponseDTO::fromEntity(
                $match,
                $participantNames[$match->player1Id()] ?? __('tournaments::messages.participants.unknown'),
                $match->player2Id() !== null
                    ? ($participantNames[$match->player2Id()] ?? (string) __('tournaments::messages.participants.unknown'))
                    : null,
                // A report only waits on a match without result. The page only says whether it is
                // pending or disputed, so the reporter's name is not looked up
                report: isset($reports[$match->id()->value]) && ! $match->isCompleted()
                    ? MatchReportResponseDTO::fromEntity($reports[$match->id()->value], '')
                    : null,
            ),
            $matches
        );
    }

    public function findPlayerMatch(string $tournamentId, string $userId): ?PlayerMatchResponseDTO
    {
        $tournament = $this->tournaments->find(TournamentId::fromString($tournamentId));
        $round = $this->rounds->findCurrentRound($tournamentId);
        $participant = $this->participantRepository->findByUserAndTournament($userId, $tournamentId);

        if (! $tournament instanceof Tournament || ! $round instanceof Round || ! $participant instanceof Participant) {
            return null;
        }

        $match = $this->matchRepository->findByParticipantAndRound($participant->id()->value, $round->id()->value);
        if (! $match instanceof TournamentMatch) {
            return null;
        }

        $names = $this->buildParticipantNamesCache([$match]);
        $side = $match->player1Id() === $participant->id()->value ? 1 : 2;
        $opponentId = $side === 1 ? $match->player2Id() : $match->player1Id();
        $opponent = $opponentId !== null ? $this->participantRepository->find(ParticipantId::fromString($opponentId)) : null;
        $report = $match->isCompleted() ? null : $this->reports->find($match->id()->value);
        $reportResponse = $report instanceof MatchReport ? $this->reportResponse($report) : null;

        return new PlayerMatchResponseDTO(
            match: MatchResponseDTO::fromEntity(
                $match,
                $names[$match->player1Id()] ?? __('tournaments::messages.participants.unknown'),
                $match->player2Id() !== null ? ($names[$match->player2Id()] ?? (string) __('tournaments::messages.participants.unknown')) : null,
                report: $reportResponse,
            ),
            side: $side,
            opponentName: $opponentId !== null ? ($names[$opponentId] ?? null) : null,
            opponentIsGuest: $opponent instanceof Participant && $opponent->userId() === null,
            actions: $this->rules->actionsFor($tournament, $round, $match, $report, $participant),
            report: $reportResponse,
            resultReporting: $tournament->resultReporting(),
            statDefinitions: $tournament->statDefinitions() ?? [],
        );
    }

    /**
     * @return array<MatchHistoryResponseDTO>
     */
    public function getHistory(string $matchId): array
    {
        $entries = $this->history->findByMatch($matchId);
        $users = $this->userDataProvider->getUsersInfo(array_values(array_unique(array_map(
            static fn (MatchHistory $entry): string => $entry->changedById(),
            $entries,
        ))));

        return array_map(
            static fn (MatchHistory $entry): MatchHistoryResponseDTO => MatchHistoryResponseDTO::fromEntity(
                $entry,
                $users[$entry->changedById()]['name'] ?? (string) __('tournaments::messages.participants.unknown'),
            ),
            $entries,
        );
    }

    private function reportResponse(MatchReport $report): MatchReportResponseDTO
    {
        $users = $this->userDataProvider->getUsersInfo([$report->reportedById()]);

        return MatchReportResponseDTO::fromEntity(
            $report,
            $users[$report->reportedById()]['name'] ?? (string) __('tournaments::messages.participants.unknown'),
        );
    }

    /**
     * @param  array<TournamentMatch>  $matches
     * @return array<string, string>
     */
    private function buildParticipantNamesCache(array $matches): array
    {
        $participantIds = [];
        foreach ($matches as $match) {
            $participantIds[$match->player1Id()] = true;
            if ($match->player2Id() !== null) {
                $participantIds[$match->player2Id()] = true;
            }
        }

        $participantIds = array_keys($participantIds);
        $namesCache = [];
        $userIds = [];

        foreach ($participantIds as $participantId) {
            $participant = $this->participantRepository->find(
                ParticipantId::fromString($participantId)
            );

            if (! $participant instanceof Participant) {
                $namesCache[$participantId] = __('tournaments::messages.participants.unknown');

                continue;
            }

            if ($participant->userId() !== null) {
                $userIds[$participant->userId()] = $participantId;
            } else {
                $namesCache[$participantId] = $participant->guestName()
                    ?? __('tournaments::messages.participants.unknown');
            }
        }

        if ($userIds !== []) {
            $usersInfo = $this->userDataProvider->getUsersInfo(array_keys($userIds));
            foreach ($userIds as $userId => $participantId) {
                $namesCache[$participantId] = $usersInfo[$userId]['name']
                    ?? __('tournaments::messages.participants.unknown');
            }
        }

        return $namesCache;
    }
}
