<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use DomainException;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;
use Modules\Tournaments\Domain\ValueObjects\MatchReportActions;

/**
 * Who may report, correct, confirm or dispute the result of a match, and when: one of its
 * players with an account, while the tournament and the round are in progress and the match has
 * no official result yet.
 */
final class MatchReportRules
{
    public function actionsFor(
        Tournament $tournament,
        Round $round,
        TournamentMatch $match,
        ?MatchReport $report,
        ?Participant $actor,
    ): MatchReportActions {
        $mayReport = $this->passes(fn () => $this->assertCanReport($tournament, $round, $match, $report, $actor));

        return new MatchReportActions(
            canReport: $mayReport && ! $report instanceof MatchReport,
            canCorrect: $mayReport && $report instanceof MatchReport,
            canConfirm: $this->passes(fn () => $this->assertCanConfirm($tournament, $round, $match, $report, $actor)),
            canDispute: $this->passes(fn () => $this->assertCanDispute($tournament, $round, $match, $report, $actor)),
        );
    }

    /**
     * Report the result, or correct one's own report while it waits for confirmation.
     *
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     */
    public function assertCanReport(
        Tournament $tournament,
        Round $round,
        TournamentMatch $match,
        ?MatchReport $report,
        ?Participant $actor,
    ): void {
        $userId = $this->assertOpenToPlayer($tournament, $round, $match, $actor);

        if ($report instanceof MatchReport && $report->reportedById() !== $userId) {
            throw MatchReportClosedException::alreadyReportedByOpponent($match->id()->value);
        }
    }

    /**
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     * @throws ResultNotConfirmedException
     */
    public function assertCanConfirm(
        Tournament $tournament,
        Round $round,
        TournamentMatch $match,
        ?MatchReport $report,
        ?Participant $actor,
    ): void {
        $this->assertOpponentOfReport($tournament, $round, $match, $report, $actor);
    }

    /**
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     * @throws ResultNotConfirmedException
     */
    public function assertCanDispute(
        Tournament $tournament,
        Round $round,
        TournamentMatch $match,
        ?MatchReport $report,
        ?Participant $actor,
    ): void {
        $this->assertOpponentOfReport($tournament, $round, $match, $report, $actor);
    }

    /**
     * The opponent of the reporter acts on a report that waits for them.
     */
    private function assertOpponentOfReport(
        Tournament $tournament,
        Round $round,
        TournamentMatch $match,
        ?MatchReport $report,
        ?Participant $actor,
    ): void {
        $matchId = $match->id()->value;
        $userId = $this->assertOpenToPlayer($tournament, $round, $match, $actor);

        if ($tournament->resultReporting() !== ResultReporting::PlayersWithConfirmation) {
            throw UnauthorizedToReportResultException::confirmationNotRequired($matchId, $userId);
        }

        if (! $report instanceof MatchReport) {
            throw ResultNotConfirmedException::noPendingReport($matchId);
        }

        if ($report->reportedById() === $userId) {
            throw UnauthorizedToReportResultException::reporterCannotConfirm($matchId, $userId);
        }

        if ($report->isDisputed()) {
            throw ResultNotConfirmedException::alreadyDisputed($matchId);
        }
    }

    /**
     * The match takes reports and the actor is one of its players.
     *
     * @return string The actor's user id
     */
    private function assertOpenToPlayer(Tournament $tournament, Round $round, TournamentMatch $match, ?Participant $actor): string
    {
        $matchId = $match->id()->value;
        $userId = $actor?->userId() ?? '';

        if ($tournament->resultReporting() === ResultReporting::AdminOnly) {
            throw UnauthorizedToReportResultException::adminOnlyMode($matchId, $userId);
        }

        if (! $actor instanceof Participant || ! $match->involvesParticipant($actor->id()->value)) {
            throw UnauthorizedToReportResultException::notAParticipant($matchId, $userId);
        }

        $accountId = $actor->userId();
        if ($accountId === null) {
            throw UnauthorizedToReportResultException::noAccount($matchId);
        }

        if (! $actor->canPlay()) {
            throw UnauthorizedToReportResultException::notAllowedToPlay($matchId, $userId);
        }

        if ($tournament->status() !== TournamentStatus::InProgress) {
            throw MatchReportClosedException::tournamentNotInProgress($matchId);
        }

        if ($round->status() !== RoundStatus::InProgress) {
            throw MatchReportClosedException::roundNotInProgress($matchId);
        }

        if ($match->isBye()) {
            throw MatchReportClosedException::byeMatch($matchId);
        }

        if ($match->isCompleted()) {
            throw MatchReportClosedException::resultAlreadyFinal($matchId);
        }

        return $accountId;
    }

    /**
     * @param  callable(): void  $check
     */
    private function passes(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
