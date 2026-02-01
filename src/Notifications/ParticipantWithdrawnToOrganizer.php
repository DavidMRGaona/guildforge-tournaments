<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantWithdrawnToOrganizer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $participantName,
        private readonly string $participantEmail,
        private readonly int $remainingParticipants,
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
            ->subject(__('tournaments::messages.emails.withdrawal.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.withdrawal.greeting'))
            ->line(__('tournaments::messages.emails.withdrawal.body', [
                'name' => $this->participantName,
                'tournament' => $this->tournamentName,
            ]))
            ->line(__('tournaments::messages.emails.withdrawal.participant_info', [
                'email' => $this->participantEmail,
            ]))
            ->line(__('tournaments::messages.emails.withdrawal.remaining', [
                'count' => $this->remainingParticipants,
            ]));
    }
}
