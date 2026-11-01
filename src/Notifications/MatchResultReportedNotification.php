<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the opponent that a player reported the result of their match. Names arrive escaped for
 * Markdown; the score summary is built by the module.
 */
final class MatchResultReportedNotification extends Notification implements ShouldQueue
{
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
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
}
