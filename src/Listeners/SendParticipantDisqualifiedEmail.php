<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use Modules\Tournaments\Domain\Events\ParticipantDisqualified;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Notifications\ParticipantDisqualifiedNotification;

/**
 * Tells a participant that the organisation disqualified them.
 */
final readonly class SendParticipantDisqualifiedEmail
{
    use MailsParticipants;

    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
    ) {}

    public function handle(ParticipantDisqualified $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));
        $participant = $this->participantRepository->find(ParticipantId::fromString($event->participantId));
        $recipient = $participant !== null ? $this->recipient($participant) : null;

        if ($tournament === null || $participant === null || $recipient === null) {
            return;
        }

        $recipient->notify(new ParticipantDisqualifiedNotification(
            participantName: $this->participantName($participant, $recipient),
            tournamentName: $tournament->name(),
            reason: $event->reason,
        ));
    }
}
