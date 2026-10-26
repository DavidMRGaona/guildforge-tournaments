<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Events\ParticipantConfirmed;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantConfirmedNotification;

/**
 * Tells a participant that the organisation approved their registration.
 */
final readonly class SendParticipantConfirmedEmail
{
    use MailsParticipants;

    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
    ) {}

    public function handle(ParticipantConfirmed $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));
        $participant = $this->participantRepository->find(ParticipantId::fromString($event->participantId));
        $recipient = $participant instanceof Participant ? $this->recipient($participant) : null;

        if (! $tournament instanceof Tournament || ! $participant instanceof Participant || ! $recipient instanceof AnonymousNotifiable) {
            return;
        }

        $recipient->notify(new ParticipantConfirmedNotification(
            participantName: $this->participantName($participant, $recipient),
            tournamentName: $tournament->name(),
            eventDate: $this->eventDate($event->tournamentId),
            requiresCheckIn: $tournament->requiresCheckIn(),
            checkInStartsBefore: $tournament->checkInStartsBefore(),
            tournamentSlug: $tournament->slug(),
            notes: $event->notes,
        ));
    }
}
