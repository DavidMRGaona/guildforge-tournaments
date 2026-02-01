<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantRegisteredToOrganizer;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;

final readonly class SendParticipantRegisteredEmails
{
    public function __construct(
        private TournamentRepositoryInterface $tournamentRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private UserDataProviderInterface $userDataProvider,
    ) {}

    public function handle(ParticipantRegistered $event): void
    {
        $tournament = $this->tournamentRepository->find(TournamentId::fromString($event->tournamentId));
        if ($tournament === null) {
            return;
        }

        $participant = $this->participantRepository->find(ParticipantId::fromString($event->participantId));
        if ($participant === null) {
            return;
        }

        // Get participant info
        $participantName = $this->getParticipantName($event);
        $participantEmail = $this->getParticipantEmail($event);

        if ($participantEmail === null) {
            return;
        }

        // Get event date for the email
        $eventDate = $this->getEventDate($event->tournamentId);

        // Send to participant (include cancellation token for guests)
        $participantNotifiable = new AnonymousNotifiable($participantEmail, $participantName);
        $participantNotifiable->notify(new ParticipantRegisteredToParticipant(
            tournamentName: $tournament->name(),
            participantName: $participantName,
            eventDate: $eventDate,
            requiresCheckIn: $tournament->requiresCheckIn(),
            checkInStartsBefore: $tournament->checkInStartsBefore(),
            cancellationToken: $event->isGuest ? $event->cancellationToken : null,
        ));

        // Send to organizer
        $organizerEmail = $tournament->notificationEmail();
        if ($organizerEmail !== '') {
            $currentCount = $this->participantRepository->countActiveByTournament($event->tournamentId);

            $organizerNotifiable = new AnonymousNotifiable($organizerEmail);
            $organizerNotifiable->notify(new ParticipantRegisteredToOrganizer(
                tournamentName: $tournament->name(),
                participantName: $participantName,
                participantEmail: $participantEmail,
                isGuest: $event->isGuest,
                currentParticipantCount: $currentCount,
                maxParticipants: $tournament->maxParticipants(),
            ));
        }
    }

    private function getParticipantName(ParticipantRegistered $event): string
    {
        if ($event->userId !== null) {
            $userInfo = $this->userDataProvider->getUserInfo($event->userId);

            return $userInfo['name'] ?? __('tournaments::messages.participants.unknown');
        }

        $participant = $this->participantRepository->find(ParticipantId::fromString($event->participantId));

        return $participant?->guestName() ?? __('tournaments::messages.participants.unknown');
    }

    private function getParticipantEmail(ParticipantRegistered $event): ?string
    {
        if ($event->guestEmail !== null) {
            return $event->guestEmail;
        }

        if ($event->userId !== null) {
            $user = UserModel::find($event->userId);

            return $user?->email;
        }

        return null;
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
