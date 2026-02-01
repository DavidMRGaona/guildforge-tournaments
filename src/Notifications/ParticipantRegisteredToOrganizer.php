<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantRegisteredToOrganizer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $participantName,
        private readonly string $participantEmail,
        private readonly bool $isGuest,
        private readonly int $currentParticipantCount,
        private readonly ?int $maxParticipants,
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
        $participantType = $this->isGuest
            ? __('tournaments::messages.emails.new_registration.guest')
            : __('tournaments::messages.emails.new_registration.registered_user');

        $capacityInfo = $this->maxParticipants !== null
            ? __('tournaments::messages.emails.new_registration.capacity', [
                'current' => $this->currentParticipantCount,
                'max' => $this->maxParticipants,
            ])
            : __('tournaments::messages.emails.new_registration.participants', [
                'count' => $this->currentParticipantCount,
            ]);

        return (new MailMessage)
            ->subject(__('tournaments::messages.emails.new_registration.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.new_registration.greeting'))
            ->line(__('tournaments::messages.emails.new_registration.body', [
                'tournament' => $this->tournamentName,
            ]))
            ->line(__('tournaments::messages.emails.new_registration.participant_info', [
                'name' => $this->participantName,
                'email' => $this->participantEmail,
                'type' => $participantType,
            ]))
            ->line($capacityInfo);
    }
}
