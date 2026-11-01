<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\DTOs\MatchReportOutcome;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Events\MatchResultConfirmed;
use Modules\Tournaments\Domain\Events\MatchResultDisputed;
use Modules\Tournaments\Domain\Events\MatchResultReported;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\Services\MatchReportRules;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

/**
 * Every change runs under the tournament's lock and reads the match again inside it, so two
 * requests at once (a confirmation and a dispute, two reports) never both succeed.
 */
final readonly class MatchReportingService implements MatchReportingServiceInterface
{
    public function __construct(
        private MatchRepositoryInterface $matches,
        private RoundRepositoryInterface $rounds,
        private TournamentRepositoryInterface $tournaments,
        private ParticipantRepositoryInterface $participants,
        private MatchReportRepositoryInterface $reports,
        private MatchResultServiceInterface $results,
        private RegistrationLockInterface $lock,
        private MatchReportRules $rules,
        private Clock $clock,
    ) {}

    public function report(ReportMatchResultDTO $dto): MatchReportOutcome
    {
        if (! $dto->result->isReportableByPlayers()) {
            throw InvalidMatchResultException::notAPlayerResult($dto->matchId);
        }

        if ($dto->result->isContradictedBy($dto->player1Score, $dto->player2Score)) {
            throw InvalidMatchResultException::scoresContradictResult($dto->matchId);
        }

        return $this->underLock($dto->matchId, function (Tournament $tournament, Round $round, TournamentMatch $match, ?MatchReport $report) use ($dto): MatchReportOutcome {
            $actor = $this->participants->findByUserAndTournament($dto->reportedById, $tournament->id()->value);
            $this->rules->assertCanReport($tournament, $round, $match, $report, $actor);

            $requiresConfirmation = $tournament->resultReporting() === ResultReporting::PlayersWithConfirmation;

            if ($requiresConfirmation) {
                // A correction that changes nothing is not news for the opponent
                if (! $this->storeReport($dto, $report)) {
                    return MatchReportOutcome::AwaitingConfirmation;
                }
            } else {
                $this->results->recordResult(new RecordMatchResultDTO(
                    matchId: $dto->matchId,
                    result: $dto->result,
                    reportedById: $dto->reportedById,
                    player1Stats: $dto->player1Stats,
                    player2Stats: $dto->player2Stats,
                    player1Score: $dto->player1Score,
                    player2Score: $dto->player2Score,
                    action: MatchHistoryAction::Reported,
                ));
            }

            $this->announce(MatchResultReported::create($dto->matchId, $tournament->id()->value, $dto->reportedById, $requiresConfirmation, $this->clock->now()));

            return $requiresConfirmation ? MatchReportOutcome::AwaitingConfirmation : MatchReportOutcome::Recorded;
        });
    }

    public function confirm(string $matchId, string $userId, string $seenReport): void
    {
        $this->underLock($matchId, function (Tournament $tournament, Round $round, TournamentMatch $match, ?MatchReport $report) use ($matchId, $userId, $seenReport): void {
            $actor = $this->participants->findByUserAndTournament($userId, $tournament->id()->value);
            $this->rules->assertCanConfirm($tournament, $round, $match, $report, $actor);

            if ($report instanceof MatchReport) {
                $this->assertUnchanged($report, $seenReport);
                $this->makeOfficial($report, $userId);
                $this->announce(MatchResultConfirmed::create($matchId, $tournament->id()->value, $userId, $this->clock->now()));
            }
        });
    }

    public function dispute(string $matchId, string $userId, string $reason, string $seenReport): void
    {
        $this->underLock($matchId, function (Tournament $tournament, Round $round, TournamentMatch $match, ?MatchReport $report) use ($matchId, $userId, $reason, $seenReport): void {
            $actor = $this->participants->findByUserAndTournament($userId, $tournament->id()->value);
            $this->rules->assertCanDispute($tournament, $round, $match, $report, $actor);

            if ($report instanceof MatchReport) {
                $this->assertUnchanged($report, $seenReport);
                $report->dispute($userId, $reason, $this->clock->now());
                $this->reports->save($report);
                $this->announce(MatchResultDisputed::create($matchId, $tournament->id()->value, $userId, $this->clock->now()));
            }
        });
    }

    public function confirmAsOrganizer(string $matchId, string $organizerId, string $seenReport): void
    {
        $this->underLock($matchId, function (Tournament $tournament, Round $round, TournamentMatch $match, ?MatchReport $report) use ($matchId, $organizerId, $seenReport): void {
            if (! $report instanceof MatchReport) {
                throw ResultNotConfirmedException::noPendingReport($matchId);
            }

            if ($match->isCompleted()) {
                throw MatchReportClosedException::resultAlreadyFinal($matchId);
            }

            $this->assertUnchanged($report, $seenReport);

            $this->makeOfficial($report, $organizerId);
            $this->announce(MatchResultConfirmed::create($matchId, $tournament->id()->value, $organizerId, $this->clock->now()));
        });
    }

    public function confirmPendingInRound(string $roundId, string $organizerId): int
    {
        $round = $this->rounds->findOrFail(RoundId::fromString($roundId));

        return $this->lock->forTournament($round->tournamentId(), function () use ($round, $roundId, $organizerId): int {
            $pending = array_filter(
                $this->reports->findByRound($roundId),
                static fn (MatchReport $report): bool => ! $report->isDisputed(),
            );

            if ($pending === []) {
                return 0;
            }

            // One batch: the standings are recalculated once
            $this->results->recordResults(array_map(
                fn (MatchReport $report): RecordMatchResultDTO => $this->confirmationOf($report, $organizerId),
                array_values($pending),
            ));

            $now = $this->clock->now();
            foreach ($pending as $report) {
                $this->announce(MatchResultConfirmed::create($report->matchId(), $round->tournamentId(), $organizerId, $now));
            }

            return count($pending);
        });
    }

    /**
     * Run the callback under the lock of the match's tournament, with everything read again.
     *
     * @template T
     *
     * @param  callable(Tournament, Round, TournamentMatch, MatchReport|null): T  $callback
     * @return T
     */
    private function underLock(string $matchId, callable $callback): mixed
    {
        $match = $this->matches->findOrFail(MatchId::fromString($matchId));
        $round = $this->rounds->findOrFail(RoundId::fromString($match->roundId()));

        return $this->lock->forTournament($round->tournamentId(), function () use ($matchId, $callback): mixed {
            $match = $this->matches->findOrFail(MatchId::fromString($matchId));
            $round = $this->rounds->findOrFail(RoundId::fromString($match->roundId()));
            $tournament = $this->tournaments->findOrFail(TournamentId::fromString($round->tournamentId()));

            return $callback($tournament, $round, $match, $this->reports->find($matchId));
        });
    }

    /**
     * @throws ResultNotConfirmedException When the report was corrected after it was shown
     */
    private function assertUnchanged(MatchReport $report, string $seenReport): void
    {
        if (! hash_equals($report->fingerprint(), $seenReport)) {
            throw ResultNotConfirmedException::reportChanged($report->matchId());
        }
    }

    /**
     * @return bool Whether the report says something new
     */
    private function storeReport(ReportMatchResultDTO $dto, ?MatchReport $report): bool
    {
        $now = $this->clock->now();

        if ($report instanceof MatchReport) {
            $sameValues = $report->result() === $dto->result
                && $report->player1Score() === $dto->player1Score
                && $report->player2Score() === $dto->player2Score
                && $report->player1Stats() == $dto->player1Stats
                && $report->player2Stats() == $dto->player2Stats;
            // Sending the same again keeps it as it is, a dispute included
            if ($sameValues) {
                return false;
            }

            $report->correct($dto->result, $dto->player1Score, $dto->player2Score, $dto->player1Stats, $dto->player2Stats, $now);
        } else {
            $report = new MatchReport(
                matchId: $dto->matchId,
                result: $dto->result,
                reportedById: $dto->reportedById,
                reportedAt: $now,
                player1Score: $dto->player1Score,
                player2Score: $dto->player2Score,
                player1Stats: $dto->player1Stats,
                player2Stats: $dto->player2Stats,
            );
        }

        $this->reports->save($report);

        return true;
    }

    /**
     * The report becomes the official result; recording it discards the report.
     */
    private function makeOfficial(MatchReport $report, string $confirmedById): void
    {
        $this->results->recordResult($this->confirmationOf($report, $confirmedById));
    }

    private function confirmationOf(MatchReport $report, string $confirmedById): RecordMatchResultDTO
    {
        return new RecordMatchResultDTO(
            matchId: $report->matchId(),
            result: $report->result(),
            reportedById: $report->reportedById(),
            player1Stats: $report->player1Stats(),
            player2Stats: $report->player2Stats(),
            player1Score: $report->player1Score(),
            player2Score: $report->player2Score(),
            action: MatchHistoryAction::Confirmed,
            confirmedById: $confirmedById,
            changedById: $confirmedById,
        );
    }

    private function announce(object $event): void
    {
        DB::afterCommit(static function () use ($event): void {
            event($event);
        });
    }
}
