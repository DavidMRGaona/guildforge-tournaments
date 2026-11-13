<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Notifications\Concerns\NoticesTournamentOnTheSite;
use Modules\Tournaments\TournamentsServiceProvider;

final class ParticipantRegisteredToParticipant extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use NoticesTournamentOnTheSite;
    use Queueable;

    public function __construct(
        private readonly string $tournamentName,
        private readonly string $participantName,
        private readonly ?string $eventDate,
        private readonly bool $requiresCheckIn,
        private readonly ?int $checkInStartsBefore,
        private readonly ?string $cancellationToken = null,
        private readonly ?string $tournamentSlug = null,
        private readonly ?string $notes = null,
        private readonly ?bool $awaitingApproval = null,
        private readonly ?bool $selfCheckInAllowed = null,
        private readonly ?string $tournamentId = null,
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
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // ?? like isset(): a notification queued before these properties existed comes back
        // without them, and reading an uninitialized property directly throws
        $notes = $this->notes ?? null;
        $awaitingApproval = $this->awaitingApproval ?? false;

        // Until the organisation approves it, the registration is only received
        $texts = $awaitingApproval ? 'registration_pending' : 'registration_confirmed';

        $message = (new MailMessage)
            ->subject(__("tournaments::messages.emails.{$texts}.subject", [
                'tournament' => $this->tournamentName,
            ]))
            ->greeting(__('tournaments::messages.emails.registration_confirmed.greeting', [
                'name' => $this->participantName,
            ]))
            ->line(__("tournaments::messages.emails.{$texts}.body", [
                'tournament' => $this->tournamentName,
            ]));

        if ($notes !== null && $notes !== '') {
            $message->line('**'.__('tournaments::messages.emails.organisation_notes').':** '.$notes);
        }

        if ($this->eventDate !== null) {
            $message->line(__('tournaments::messages.emails.registration_confirmed.event_date', [
                'date' => $this->eventDate,
            ]));
        }

        // ?? like isset(): a notification queued before these properties existed comes back without
        // them, and reading an uninitialized property directly throws
        $tournamentSlug = $this->tournamentSlug ?? null;

        // The approval email reminds them of the check-in
        if ($this->requiresCheckIn && ! $awaitingApproval) {
            $message->line(CheckInReminderLine::for($this->checkInStartsBefore, $this->selfCheckInAllowed ?? null, $tournamentSlug));
        }

        $tournamentUrl = $tournamentSlug !== null ? route('tournaments.show', $tournamentSlug) : null;

        // Guests get the cancellation button; accounts manage it from the tournament page
        if ($this->cancellationToken !== null) {
            $message->line(__('tournaments::messages.emails.registration_confirmed.cancel_intro'))
                ->action(
                    __('tournaments::messages.emails.registration_confirmed.cancel_link'),
                    route('tournaments.cancel-confirmation', $this->cancellationToken),
                );

            if ($tournamentUrl !== null) {
                $message->line(__('tournaments::messages.emails.view_tournament_line', ['url' => $tournamentUrl]));
            }
        } elseif ($tournamentUrl !== null) {
            $message->action(__('tournaments::messages.emails.view_tournament'), $tournamentUrl);
        }

        // Add GDPR notice
        $message->line(__('tournaments::messages.emails.registration_confirmed.gdpr_notice'));

        return $awaitingApproval
            ? $message
            : $message->line(__('tournaments::messages.emails.registration_confirmed.closing'));
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $tournament = $this->visibleTournamentFor($notifiable);

        if (! $tournament instanceof TournamentResponseDTO) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __('tournaments::messages.site.registered', ['tournament' => $this->tournamentName]),
            url: $this->tournamentPath($tournament),
            icon: 'user-plus',
        );
    }
}
