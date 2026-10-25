<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use Modules\Tournaments\Domain\Events\TournamentCancelled;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Notifications\TournamentCancelledNotification;

/**
 * Tells every active participant that the organisation cancelled the tournament.
 */
final readonly class SendTournamentCancelledEmails
{
    use MailsParticipants;

    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
    ) {}

    public function handle(TournamentCancelled $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));

        if (!$tournament instanceof \Modules\Tournaments\Domain\Entities\Tournament) {
            return;
        }

        $eventDate = $this->eventDate($event->tournamentId);

        foreach ($this->participantRepository->findByTournament($event->tournamentId) as $participant) {
            $recipient = $participant->isActive() ? $this->recipient($participant) : null;

            if (!$recipient instanceof \Modules\Tournaments\Notifications\AnonymousNotifiable) {
                continue;
            }

            $recipient->notify(new TournamentCancelledNotification(
                participantName: $this->participantName($participant, $recipient),
                tournamentName: $tournament->name(),
                eventDate: $eventDate,
            ));
        }
    }
}
