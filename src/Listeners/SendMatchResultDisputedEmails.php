<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Notifications\Support\EscapesMarkdown;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Events\MatchResultDisputed;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Listeners\Concerns\DescribesMatches;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Listeners\Concerns\NoticesTournamentManagers;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\MatchResultDisputedNotification;
use Modules\Tournaments\Notifications\TournamentManagementNotification;

/**
 * Tells the organization, which decides (its address by mail, the accounts that record results in
 * the bell), and the reporter, who may correct the report, that the opponent disputed it.
 */
final readonly class SendMatchResultDisputedEmails
{
    use DescribesMatches;
    use MailsParticipants;
    use NoticesTournamentManagers;

    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private MatchRepositoryInterface $matches,
        private ParticipantRepositoryInterface $participants,
        private MatchReportRepositoryInterface $reports,
        private UserDataProviderInterface $users,
        private PermissionHolderFinder $managers,
    ) {}

    public function handle(MatchResultDisputed $event): void
    {
        $tournament = $this->tournaments->find(TournamentId::fromString($event->tournamentId));
        $match = $this->matches->find(MatchId::fromString($event->matchId));
        $report = $this->reports->find($event->matchId);

        if (! $tournament instanceof Tournament || ! $match instanceof TournamentMatch || ! $report instanceof MatchReport || $match->player2Id() === null) {
            return;
        }

        $one = $this->participants->find(ParticipantId::fromString($match->player1Id()));
        $two = $this->participants->find(ParticipantId::fromString($match->player2Id()));
        $reporter = $this->participants->findByUserAndTournament($report->reportedById(), $event->tournamentId);
        $disputer = $this->participants->findByUserAndTournament($event->disputedByUserId, $event->tournamentId);

        $notification = fn (?string $recipientName, bool $toOrganization): MatchResultDisputedNotification => new MatchResultDisputedNotification(
            recipientName: $recipientName,
            disputerName: $this->nameOf($disputer),
            tournamentName: $tournament->name(),
            tournamentSlug: $tournament->slug(),
            result: $this->resultSummary($report->result(), $report->player1Score(), $report->player2Score(), $this->nameOf($one), $this->nameOf($two)),
            reason: EscapesMarkdown::escape((string) $report->disputeReason()),
            toOrganization: $toOrganization,
            tournamentId: $event->tournamentId,
            disputerId: $event->disputedByUserId,
        );

        if ($tournament->notificationEmail() !== '') {
            (new AnonymousNotifiable($tournament->notificationEmail()))->notify($notification(null, true));
        }

        // The bell is not Markdown: plain names there
        $this->noticeManagers(new TournamentManagementNotification(
            kind: TournamentManagementNotification::DISPUTED,
            tournamentId: $event->tournamentId,
            tournamentName: $tournament->name(),
            actorName: $this->plainNameOf($disputer),
            actorId: $event->disputedByUserId,
            body: $this->resultSummary($report->result(), $report->player1Score(), $report->player2Score(), $this->plainNameOf($one), $this->plainNameOf($two)),
        ));

        $recipient = $reporter instanceof Participant ? $this->recipient($reporter) : null;
        if (is_object($recipient)) {
            $recipient->notify($notification($this->nameOf($reporter), false));
        }
    }
}
