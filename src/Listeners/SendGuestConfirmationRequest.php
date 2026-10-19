<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\GuestConfirmationRequestNotification;

final readonly class SendGuestConfirmationRequest
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
    ) {}

    public function handle(GuestConfirmationRequested $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));
        if ($tournament === null) {
            return;
        }

        (new AnonymousNotifiable($event->guestEmail, $event->guestName))->notify(new GuestConfirmationRequestNotification(
            tournamentName: $tournament->name(),
            guestName: $event->guestName,
            confirmationToken: $event->confirmationToken,
            expiresAt: $event->expiresAt,
            seatHeld: $event->seatHeld,
        ));
    }
}
