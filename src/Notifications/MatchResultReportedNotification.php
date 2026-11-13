<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Notifications\Concerns\NoticesTournamentOnTheSite;
use Modules\Tournaments\TournamentsServiceProvider;

/**
 * Tells the opponent that a player reported the result of their match. Names arrive escaped for
 * Markdown, for the mail, and as people wrote them, for the bell; the score summary is built by
 * the module.
 */
final class MatchResultReportedNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use NoticesTournamentOnTheSite;
    use Queueable;

    // The opponent confirms or disputes from the tournament page
    public const string TO_CONFIRM = 'to_confirm';

    // The tournament trusts the players: the result already counts
    public const string TRUSTED = 'trusted';

    // A guest opponent cannot confirm: the organization does
    public const string ORGANIZATION_CONFIRMS = 'organization_confirms';

    public function __construct(
        private readonly string $recipientName,
        private readonly string $reporterName,
        private readonly string $tournamentName,
        private readonly string $tournamentSlug,
        private readonly string $result,
        private readonly string $mode,
        private readonly ?string $tournamentId = null,
        private readonly ?string $reporterId = null,
        private readonly ?string $plainReporterName = null,
        private readonly ?string $plainResult = null,
    ) {}

    public function mailCategory(): string
    {
        return TournamentsServiceProvider::MAIL_RESULTS;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('tournaments::messages.emails.match_result_reported.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.match_result_reported.greeting', [
                'name' => $this->recipientName,
            ]))
            ->line(__('tournaments::messages.emails.match_result_reported.intro', [
                'reporter' => $this->reporterName,
                'tournament' => $this->tournamentName,
                'result' => $this->result,
            ]))
            ->line(__('tournaments::messages.emails.match_result_reported.'.$this->mode))
            ->action(
                $this->mode === self::TO_CONFIRM
                    ? __('tournaments::messages.emails.match_result_reported.review')
                    : __('tournaments::messages.emails.view_tournament'),
                route('tournaments.show', $this->tournamentSlug),
            );
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $tournament = $this->visibleTournamentFor($notifiable);

        if (! $tournament instanceof TournamentResponseDTO) {
            return null;
        }

        // The bell is not Markdown. A notice queued before the plain names existed keeps the escaped ones
        return new SiteNotificationDTO(
            title: __('tournaments::messages.site.result_reported.'.$this->mode, [
                'reporter' => $this->plainReporterName ?? $this->reporterName,
                'tournament' => $this->tournamentName,
            ]),
            url: $this->tournamentPath($tournament),
            body: $this->plainResult ?? $this->result,
            icon: 'swords',
            groupKey: self::SUBJECT_TYPE.':'.$tournament->id,
            actorId: $this->reporterId ?? null,
            groupedTitle: fn (SiteNotificationGroupDTO $group): string => trans_choice('tournaments::messages.site.results_grouped', $group->count, [
                'count' => $group->count,
                'tournament' => $this->tournamentName,
            ]),
        );
    }
}
