<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;

final readonly class MatchResultService implements MatchResultServiceInterface
{
    public function __construct(
        private MatchRepositoryInterface $matches,
        private RoundRepositoryInterface $rounds,
        private StandingsServiceInterface $standings,
    ) {
    }

    public function recordResult(RecordMatchResultDTO $dto): void
    {
        DB::transaction(function () use ($dto): void {
            $match = $this->matches->findOrFail(MatchId::fromString($dto->matchId));

            if ($match->isBye() && $dto->result !== MatchResult::Bye) {
                throw InvalidMatchResultException::byeMatchOnlyAcceptsBye($dto->matchId);
            }

            if (! $match->isBye() && $dto->result === MatchResult::Bye) {
                throw InvalidMatchResultException::byeOnlyForByeMatches($dto->matchId);
            }

            if ($dto->result === MatchResult::NotPlayed) {
                $match->resetResult();
            } else {
                $match->reportResult(
                    result: $dto->result,
                    reportedById: $dto->reportedById,
                    player1Stats: $dto->player1Stats,
                    player2Stats: $match->isBye() ? null : $dto->player2Stats,
                );
            }

            $this->matches->save($match);

            $round = $this->rounds->findOrFail(RoundId::fromString($match->roundId()));
            $this->standings->recalculate($round->tournamentId());
        });
    }
}
