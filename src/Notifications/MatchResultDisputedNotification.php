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
use Modules\Tournaments\Notifications\Concerns\NoticesTournamentOnTheSite;
use Modules\Tournaments\TournamentsServiceProvider;

/**
 * Tells the organization and the reporter that the opponent disputed a reported result. Names and
 * reason arrive escaped for Markdown.
 */
final class MatchResultDisputedNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use NoticesTournamentOnTheSite;
    use Queueable;

    public function __construct(
        private readonly ?string $recipientName,
        private readonly string $disputerName,
        private readonly string $tournamentName,
        private readonly string $tournamentSlug,
        private readonly string $result,
        private readonly string $reason,
        private readonly bool $toOrganization,
        private readonly ?string $tournamentId = null,
        private readonly ?string $disputerId = null,
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
        $message = (new MailMessage)
            ->subject(__('tournaments::messages.emails.match_result_disputed.subject', [
                'tournament' => $this->tournamentName,
            ]));

        if ($this->recipientName !== null) {
            $message->greeting(__('tournaments::messages.emails.match_result_disputed.greeting', [
                'name' => $this->recipientName,
            ]));
        }

        $message
            ->line(__('tournaments::messages.emails.match_result_disputed.intro', [
                'disputer' => $this->disputerName,
                'tournament' => $this->tournamentName,
                'result' => $this->result,
            ]))
            ->line('**'.__('tournaments::messages.emails.match_result_disputed.reason').':** '.$this->reason);

        if ($this->toOrganization) {
            return $message->line(__('tournaments::messages.emails.match_result_disputed.organization'));
        }

        return $message
            ->line(__('tournaments::messages.emails.match_result_disputed.reporter'))
            ->action(__('tournaments::messages.emails.view_tournament'), route('tournaments.show', $this->tournamentSlug));
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        // The copy to the tournament's address goes by mail only: it names no account
        if ($this->toOrganization) {
            return null;
        }

        $tournament = $this->visibleTournamentFor($notifiable);

        if ($tournament === null) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __('tournaments::messages.site.result_disputed', [
                'disputer' => $this->disputerName,
                'tournament' => $this->tournamentName,
            ]),
            url: $this->tournamentPath($tournament),
            body: $this->result,
            icon: 'swords',
            groupKey: self::SUBJECT_TYPE.':'.$tournament->id,
            actorId: $this->disputerId ?? null,
            groupedTitle: fn (SiteNotificationGroupDTO $group): string => trans_choice('tournaments::messages.site.results_grouped', $group->count, [
                'count' => $group->count,
                'tournament' => $this->tournamentName,
            ]),
        );
    }
}
