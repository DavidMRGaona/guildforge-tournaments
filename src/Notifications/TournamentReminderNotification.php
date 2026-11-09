<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\TournamentsServiceProvider;

final class TournamentReminderNotification extends Notification implements HasMailCategory, ShouldQueue
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
        private readonly ?bool $selfCheckInAllowed = null,
    ) {}

    public function mailCategory(): string
    {
        return TournamentsServiceProvider::MAIL_REMINDERS;
    }

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
            // ?? like isset(): a notification queued before the property existed comes back without it
            $message->line(CheckInReminderLine::for($this->checkInStartsBefore, $this->selfCheckInAllowed ?? null, $this->tournamentSlug));
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
