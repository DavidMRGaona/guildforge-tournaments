<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;

final readonly class MatchResultService implements MatchResultServiceInterface
{
    public function __construct(
        private MatchRepositoryInterface $matches,
        private RoundRepositoryInterface $rounds,
        private StandingsServiceInterface $standings,
        private MatchHistoryRepositoryInterface $history,
        private MatchReportRepositoryInterface $reports,
        private Clock $clock,
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

    public function resetResult(string $matchId, string $resetById, string $reason): void
    {
        $this->recordResult(new RecordMatchResultDTO(
            matchId: $matchId,
            result: MatchResult::NotPlayed,
            reportedById: $resetById,
            action: MatchHistoryAction::Reset,
            reason: $reason,
        ));
    }

    /**
     * Validate and store one result. The official result wins over any report still waiting
     * for confirmation, which is discarded.
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

        $previousResult = $match->result();
        $previousPlayer1Score = $match->player1Score();
        $previousPlayer2Score = $match->player2Score();
        $now = $this->clock->now();

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
                at: $now,
                player1Score: $match->isBye() ? null : $dto->player1Score,
                player2Score: $match->isBye() ? null : $dto->player2Score,
                player1Stats: $dto->player1Stats,
                player2Stats: $match->isBye() ? null : $dto->player2Stats,
            );

            if ($dto->confirmedById !== null) {
                $match->confirmResult($dto->confirmedById, $now);
            }
        }

        $this->matches->save($match);

        $hadReport = $this->reports->find($dto->matchId) instanceof MatchReport;
        if ($hadReport) {
            $this->reports->delete($dto->matchId);
        }

        $changed = $previousResult !== $match->result()
            || $previousPlayer1Score !== $match->player1Score()
            || $previousPlayer2Score !== $match->player2Score();

        // Discarding a waiting report is a reset too, even with no result to clear
        if ($changed || ($hadReport && $dto->action === MatchHistoryAction::Reset)) {
            $this->history->save(MatchHistory::fromResultChange(
                matchId: $dto->matchId,
                previousResult: $previousResult,
                newResult: $match->result(),
                previousPlayer1Score: $previousPlayer1Score,
                newPlayer1Score: $match->player1Score(),
                previousPlayer2Score: $previousPlayer2Score,
                newPlayer2Score: $match->player2Score(),
                changedById: $dto->changedById ?? $dto->reportedById,
                reason: $dto->reason,
                changedAt: $now,
                action: $dto->action,
            ));
        }

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
