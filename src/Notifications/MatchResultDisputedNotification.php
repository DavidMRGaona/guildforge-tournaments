<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the organization and the reporter that the opponent disputed a reported result. Names and
 * reason arrive escaped for Markdown.
 */
final class MatchResultDisputedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $recipientName,
        private readonly string $disputerName,
        private readonly string $tournamentName,
        private readonly string $tournamentSlug,
        private readonly string $result,
        private readonly string $reason,
        private readonly bool $toOrganization,
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
}
