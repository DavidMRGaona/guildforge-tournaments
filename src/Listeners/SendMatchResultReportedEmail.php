<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Events\MatchResultReported;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Listeners\Concerns\DescribesMatches;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\MatchResultReportedNotification;

/**
 * Tells the opponent what a player reported: to confirm or dispute it, or that it already counts.
 */
final readonly class SendMatchResultReportedEmail
{
    use DescribesMatches;
    use MailsParticipants;

    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private MatchRepositoryInterface $matches,
        private ParticipantRepositoryInterface $participants,
        private MatchReportRepositoryInterface $reports,
        private UserDataProviderInterface $users,
    ) {}

    public function handle(MatchResultReported $event): void
    {
        $tournament = $this->tournaments->find(TournamentId::fromString($event->tournamentId));
        $match = $this->matches->find(MatchId::fromString($event->matchId));
        $reporter = $this->participants->findByUserAndTournament($event->reportedByUserId, $event->tournamentId);

        if (! $tournament instanceof Tournament || ! $match instanceof TournamentMatch || ! $reporter instanceof Participant || $match->isBye()) {
            return;
        }

        $opponentId = $match->player1Id() === $reporter->id()->value ? $match->player2Id() : $match->player1Id();
        $opponent = $opponentId !== null ? $this->participants->find(ParticipantId::fromString($opponentId)) : null;
        $recipient = $opponent instanceof Participant ? $this->recipient($opponent) : null;

        if (! $opponent instanceof Participant || ! $recipient instanceof AnonymousNotifiable) {
            return;
        }

        // What was reported: the waiting report, or the result it already became
        $report = $event->requiresConfirmation ? $this->reports->find($event->matchId) : null;
        if ($event->requiresConfirmation && ! $report instanceof MatchReport) {
            return;
        }

        [$one, $two] = $match->player1Id() === $reporter->id()->value ? [$reporter, $opponent] : [$opponent, $reporter];

        $recipient->notify(new MatchResultReportedNotification(
            recipientName: $this->nameOf($opponent),
            reporterName: $this->nameOf($reporter),
            tournamentName: $tournament->name(),
            tournamentSlug: $tournament->slug(),
            result: $this->resultSummary(
                $report?->result() ?? $match->result(),
                $report instanceof MatchReport ? $report->player1Score() : $match->player1Score(),
                $report instanceof MatchReport ? $report->player2Score() : $match->player2Score(),
                $this->nameOf($one),
                $this->nameOf($two),
            ),
            mode: match (true) {
                ! $event->requiresConfirmation => MatchResultReportedNotification::TRUSTED,
                $opponent->userId() === null => MatchResultReportedNotification::ORGANIZATION_CONFIRMS,
                default => MatchResultReportedNotification::TO_CONFIRM,
            },
        ));
    }
}
