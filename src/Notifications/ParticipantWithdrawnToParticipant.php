<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantWithdrawnToParticipant extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $participantName,
        private readonly string $tournamentName,
        private readonly ?string $eventDate,
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
        $message = (new MailMessage())
            ->subject(__('tournaments::messages.emails.withdrawal_confirmed.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.withdrawal_confirmed.greeting', [
                'name' => $this->participantName,
            ]))
            ->line(__('tournaments::messages.emails.withdrawal_confirmed.intro'))
            ->line(__('tournaments::messages.emails.withdrawal_confirmed.details'));

        $message->line('**' . __('tournaments::messages.emails.withdrawal_confirmed.tournament_name') . ':** ' . $this->tournamentName);

        if ($this->eventDate !== null) {
            $message->line('**' . __('tournaments::messages.emails.withdrawal_confirmed.event_date') . ':** ' . $this->eventDate);
        }

        return $message
            ->line(__('tournaments::messages.emails.withdrawal_confirmed.gdpr_notice'));
    }
}
