<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
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
    ) {}

    public function recordResult(RecordMatchResultDTO $dto): void
    {
        $this->recordResults([$dto]);
    }

    /**
     * @param  array<RecordMatchResultDTO>  $dtos
     */
    public function recordResults(array $dtos): void
    {
        DB::transaction(function () use ($dtos): void {
            $tournamentIds = [];
            foreach ($dtos as $dto) {
                $tournamentIds[$this->apply($dto)] = true;
            }

            foreach (array_keys($tournamentIds) as $tournamentId) {
                $this->standings->recalculate($tournamentId);
            }
        });
    }

    /**
     * Validate and store one result.
     *
     * @return string The match's tournament id
     */
    private function apply(RecordMatchResultDTO $dto): string
    {
        $match = $this->matches->findOrFail(MatchId::fromString($dto->matchId));
        $round = $this->rounds->findOrFail(RoundId::fromString($match->roundId()));

        if ($match->isBye() && $dto->result !== MatchResult::Bye) {
            throw InvalidMatchResultException::byeMatchOnlyAcceptsBye($dto->matchId);
        }

        if (! $match->isBye() && $dto->result === MatchResult::Bye) {
            throw InvalidMatchResultException::byeOnlyForByeMatches($dto->matchId);
        }

        if (! $match->isBye() && $this->scoresContradict($dto)) {
            throw InvalidMatchResultException::scoresContradictResult($dto->matchId);
        }

        if ($dto->result === MatchResult::NotPlayed) {
            // The next round was paired from this result: it may change, not disappear
            if ($match->isCompleted()
                && $round->isFinished()
                && $this->rounds->findByTournamentAndNumber($round->tournamentId(), $round->roundNumber() + 1) instanceof Round) {
                throw MatchResultLockedException::laterRoundExists($dto->matchId);
            }

            $match->resetResult();
        } else {
            $match->reportResult(
                result: $dto->result,
                reportedById: $dto->reportedById,
                player1Score: $match->isBye() ? null : $dto->player1Score,
                player2Score: $match->isBye() ? null : $dto->player2Score,
                player1Stats: $dto->player1Stats,
                player2Stats: $match->isBye() ? null : $dto->player2Stats,
            );
        }

        $this->matches->save($match);

        return $round->tournamentId();
    }

    /**
     * With both scores given, the winner cannot have scored less and a draw needs equal scores.
     */
    private function scoresContradict(RecordMatchResultDTO $dto): bool
    {
        if ($dto->player1Score === null || $dto->player2Score === null) {
            return false;
        }

        return match ($dto->result) {
            MatchResult::PlayerOneWin => $dto->player1Score < $dto->player2Score,
            MatchResult::PlayerTwoWin => $dto->player2Score < $dto->player1Score,
            MatchResult::Draw => $dto->player1Score !== $dto->player2Score,
            default => false,
        };
    }
}
