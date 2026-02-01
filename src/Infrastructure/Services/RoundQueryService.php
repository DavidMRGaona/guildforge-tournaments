<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;
use Modules\Tournaments\Application\Services\RoundQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;

final readonly class RoundQueryService implements RoundQueryServiceInterface
{
    public function __construct(
        private RoundRepositoryInterface $roundRepository,
        private MatchRepositoryInterface $matchRepository,
    ) {
    }

    /**
     * @return array<RoundResponseDTO>
     */
    public function getRoundsWithMatches(string $tournamentId): array
    {
        $rounds = $this->roundRepository->findByTournament($tournamentId);

        if ($rounds === []) {
            return [];
        }

        return array_map(function ($round) {
            $matches = $this->matchRepository->findByRound($round->id()->value);
            $matchCount = count($matches);
            $completedMatchCount = 0;

            foreach ($matches as $match) {
                if ($match->result() !== MatchResult::NotPlayed) {
                    $completedMatchCount++;
                }
            }

            return RoundResponseDTO::fromEntity($round, $matchCount, $completedMatchCount);
        }, $rounds);
    }

    public function getCurrentRound(string $tournamentId): ?RoundResponseDTO
    {
        $round = $this->roundRepository->findCurrentRound($tournamentId);

        if ($round === null) {
            return null;
        }

        $matches = $this->matchRepository->findByRound($round->id()->value);
        $matchCount = count($matches);
        $completedMatchCount = 0;

        foreach ($matches as $match) {
            if ($match->result() !== MatchResult::NotPlayed) {
                $completedMatchCount++;
            }
        }

        return RoundResponseDTO::fromEntity($round, $matchCount, $completedMatchCount);
    }
}
