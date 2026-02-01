<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToOrganizer;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToParticipant;

final readonly class SendParticipantWithdrawnEmail
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
    ) {}

    public function handle(ParticipantWithdrawn $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));
        if ($tournament === null) {
            return;
        }

        $participant = $this->participantRepository->find(ParticipantId::fromString($event->participantId));
        if ($participant === null) {
            return;
        }

        // Get participant info (prefer from event, fallback to entity)
        $participantName = $event->participantName ?? $this->getParticipantName($event, $participant);
        $participantEmail = $event->participantEmail ?? $this->getParticipantEmail($event, $participant);

        // Get event date for the email
        $eventDate = $this->getEventDate($event->tournamentId);

        // Send confirmation to participant if we have their email
        if ($participantEmail !== '' && $participantEmail !== null) {
            $participantNotifiable = new AnonymousNotifiable($participantEmail, $participantName);
            $participantNotifiable->notify(new ParticipantWithdrawnToParticipant(
                participantName: $participantName,
                tournamentName: $tournament->name(),
                eventDate: $eventDate,
            ));
        }

        // Send to organizer
        $organizerEmail = $tournament->notificationEmail();
        if ($organizerEmail !== '') {
            $remainingCount = $this->participantRepository->countActiveByTournament($event->tournamentId);

            $organizerNotifiable = new AnonymousNotifiable($organizerEmail);
            $organizerNotifiable->notify(new ParticipantWithdrawnToOrganizer(
                tournamentName: $tournament->name(),
                participantName: $participantName,
                participantEmail: $participantEmail ?? '',
                remainingParticipants: $remainingCount,
            ));
        }
    }

    private function getParticipantName(
        ParticipantWithdrawn $event,
        \Modules\Tournaments\Domain\Entities\Participant $participant
    ): string {
        if ($event->userId !== null) {
            $userInfo = $this->userDataProvider->getUserInfo($event->userId);

            return $userInfo['name'] ?? __('tournaments::messages.participants.unknown');
        }

        return $participant->guestName() ?? __('tournaments::messages.participants.unknown');
    }

    private function getParticipantEmail(
        ParticipantWithdrawn $event,
        \Modules\Tournaments\Domain\Entities\Participant $participant
    ): string {
        if ($participant->guestEmail() !== null) {
            return $participant->guestEmail();
        }

        if ($event->userId !== null) {
            $user = UserModel::find($event->userId);

            return $user?->email ?? '';
        }

        return '';
    }

    private function getEventDate(string $tournamentId): ?string
    {
        $tournamentModel = TournamentModel::query()
            ->where('id', $tournamentId)
            ->first(['event_id']);

        if ($tournamentModel === null) {
            return null;
        }

        $event = EventModel::query()
            ->where('id', $tournamentModel->event_id)
            ->first(['start_date']);

        if ($event === null || $event->start_date === null) {
            return null;
        }

        return $event->start_date->format('d/m/Y H:i');
    }
}
