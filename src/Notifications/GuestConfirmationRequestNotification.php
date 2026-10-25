<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link a guest follows to confirm their registration.
 */
final class GuestConfirmationRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $guestName,
        private readonly string $confirmationToken,
        private readonly ?DateTimeImmutable $expiresAt,
        private readonly bool $seatHeld,
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
            ->subject(__('tournaments::messages.emails.guest_confirmation.subject', [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.guest_confirmation.greeting', [
                'name' => $this->guestName,
            ]))
            ->line(__('tournaments::messages.emails.guest_confirmation.body', [
                'tournament' => $this->tournamentName,
            ]));

        if ($this->seatHeld && $this->expiresAt instanceof DateTimeImmutable) {
            $message->line(__('tournaments::messages.emails.guest_confirmation.hold_deadline', [
                'date' => app(TimezoneServiceInterface::class)->format($this->expiresAt),
            ]));
        } else {
            $message->line(__('tournaments::messages.emails.guest_confirmation.wait_notice'));
        }

        return $message
            ->action(
                __('tournaments::messages.emails.guest_confirmation.action'),
                route('tournaments.confirm-registration', ['token' => $this->confirmationToken]),
            )
            ->line(__('tournaments::messages.emails.guest_confirmation.closing'));
    }
}
