<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $participantName,
        private readonly string $tournamentName,
        private readonly ?string $eventDate,
        private readonly bool $requiresCheckIn,
        private readonly ?int $checkInStartsBefore,
        private readonly string $tournamentSlug,
        private readonly ?string $notes = null,
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
            ->subject(__('tournaments::messages.emails.participant_confirmed.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.participant_confirmed.greeting', [
                'name' => $this->participantName,
            ]))
            ->line(__('tournaments::messages.emails.participant_confirmed.intro', [
                'tournament' => $this->tournamentName,
            ]));

        if ($this->notes !== null && $this->notes !== '') {
            $message->line('**'.__('tournaments::messages.emails.organisation_notes').':** '.$this->notes);
        }

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

        return $message
            ->action(__('tournaments::messages.emails.view_tournament'), route('tournaments.show', $this->tournamentSlug))
            ->line(__('tournaments::messages.emails.registration_confirmed.closing'));
    }
}
