<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TournamentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $participantName,
        private readonly string $tournamentName,
        private readonly string $tournamentSlug,
        private readonly string $eventDate,
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
            ->subject(__('tournaments::messages.emails.tournament_reminder.subject', [
                'tournament' => $this->tournamentName,
                'date' => $this->eventDate,
            ]))
            ->greeting(__('tournaments::messages.emails.tournament_reminder.greeting', ['name' => $this->participantName]))
            ->line(__('tournaments::messages.emails.tournament_reminder.intro', [
                'tournament' => $this->tournamentName,
                'date' => $this->eventDate,
            ]));

        if ($this->requiresCheckIn) {
            $message->line(__('tournaments::messages.emails.registration_confirmed.check_in_required', [
                'minutes' => $this->checkInStartsBefore ?? 30,
            ]));
        }

        $message->action(
            __('tournaments::messages.emails.view_tournament'),
            route('tournaments.show', $this->tournamentSlug),
        );

        if ($this->cancellationToken !== null) {
            $message->line(__('tournaments::messages.emails.tournament_reminder.cancel', [
                'url' => route('tournaments.cancel-confirmation', $this->cancellationToken),
            ]));
        }

        return $message->line(__('tournaments::messages.emails.tournament_reminder.closing'));
    }
}
