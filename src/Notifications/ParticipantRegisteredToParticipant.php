<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantRegisteredToParticipant extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $participantName,
        private readonly ?string $eventDate,
        private readonly bool $requiresCheckIn,
        private readonly ?int $checkInStartsBefore,
        private readonly ?string $cancellationToken = null,
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
            ->subject(__('tournaments::messages.emails.registration_confirmed.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.registration_confirmed.greeting', [
                'name' => $this->participantName,
            ]))
            ->line(__('tournaments::messages.emails.registration_confirmed.body', [
                'tournament' => $this->tournamentName,
            ]));

        if ($this->eventDate !== null) {
            $message->line(__('tournaments::messages.emails.registration_confirmed.event_date', [
                'date' => $this->eventDate,
            ]));
        }

        if ($this->requiresCheckIn) {
            $message->line(__('tournaments::messages.emails.registration_confirmed.check_in_required', [
                'minutes' => $this->checkInStartsBefore ?? 30,
            ]));
        }

        // Add cancellation link for guests
        if ($this->cancellationToken !== null) {
            $cancelUrl = url("/torneos/cancelar/{$this->cancellationToken}");
            $message->line(__('tournaments::messages.emails.registration_confirmed.cancel_intro'))
                ->action(__('tournaments::messages.emails.registration_confirmed.cancel_link'), $cancelUrl);
        }

        // Add GDPR notice
        $message->line(__('tournaments::messages.emails.registration_confirmed.gdpr_notice'));

        return $message
            ->line(__('tournaments::messages.emails.registration_confirmed.closing'));
    }
}
