<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;

final readonly class MatchQueryService implements MatchQueryServiceInterface
{
    public function __construct(
        private MatchRepositoryInterface $matchRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
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

        return array_map(
            fn ($match) => MatchResponseDTO::fromEntity(
                $match,
                $participantNames[$match->player1Id()] ?? __('tournaments::messages.participants.unknown'),
                $match->player2Id() !== null
                    ? ($participantNames[$match->player2Id()] ?? __('tournaments::messages.participants.unknown'))
                    : null,
            ),
            $matches
        );
    }

    /**
     * @param  array<\Modules\Tournaments\Domain\Entities\TournamentMatch>  $matches
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

            if ($participant === null) {
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
