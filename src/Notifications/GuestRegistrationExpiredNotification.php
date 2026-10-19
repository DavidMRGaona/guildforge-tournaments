<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest that the unconfirmed registration lapsed and the place went back.
 */
final class GuestRegistrationExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $tournamentSlug,
        private readonly string $guestName,
    ) {}

    /**
     * Mails the guest; shared by the expiry command and the confirmation link that finds the row overdue.
     */
    public static function sendTo(string $guestEmail, string $guestName, string $tournamentName, string $tournamentSlug): void
    {
        (new AnonymousNotifiable($guestEmail, $guestName))
            ->notify(new self($tournamentName, $tournamentSlug, $guestName));
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
        return (new MailMessage)
            ->subject(__('tournaments::messages.emails.guest_registration_expired.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.guest_registration_expired.greeting', [
                'name' => $this->guestName,
            ]))
            ->line(__('tournaments::messages.emails.guest_registration_expired.body'))
            ->line(__('tournaments::messages.emails.guest_registration_expired.rejoin'))
            ->action(
                __('tournaments::messages.emails.guest_registration_expired.action'),
                route('tournaments.show', $this->tournamentSlug),
            );
    }
}
