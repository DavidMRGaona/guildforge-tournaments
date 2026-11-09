<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\TournamentsServiceProvider;

final class ParticipantDisqualifiedNotification extends Notification implements HasMailCategory, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $participantName,
        private readonly string $tournamentName,
        private readonly ?string $reason = null,
    ) {}

    public function mailCategory(): string
    {
        return TournamentsServiceProvider::MAIL_REGISTRATIONS;
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
            ->subject(__('tournaments::messages.emails.participant_disqualified.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.participant_disqualified.greeting', [
                'name' => $this->participantName,
            ]))
            ->line(__('tournaments::messages.emails.participant_disqualified.intro', [
                'tournament' => $this->tournamentName,
            ]));

        if ($this->reason !== null && $this->reason !== '') {
            $message->line('**'.__('tournaments::messages.emails.participant_disqualified.reason').':** '.$this->reason);
        }

        return $message->line(__('tournaments::messages.emails.participant_disqualified.outro'));
    }
}
