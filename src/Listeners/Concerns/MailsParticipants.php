<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners\Concerns;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Notifications\AnonymousNotifiable;

trait MailsParticipants
{
    /**
     * Where to mail a participant about a decision of the organisation: the account email (not
     * of an anonymised account), or a guest email only once confirmed (never an address nobody
     * has vouched for).
     */
    private function recipient(Participant $participant): ?AnonymousNotifiable
    {
        $userId = $participant->userId();

        if ($userId !== null) {
            $user = UserModel::find($userId);

            // An anonymised account keeps only a placeholder address
            return $user !== null && $user->email !== '' && $user->anonymized_at === null
                ? new AnonymousNotifiable($user->email, $user->name)
                : null;
        }

        $email = $participant->guestEmail();

        if ($email === null || $email === '' || !$participant->emailConfirmedAt() instanceof \DateTimeImmutable) {
            return null;
        }

        return new AnonymousNotifiable($email, $participant->guestName());
    }

    private function participantName(Participant $participant, AnonymousNotifiable $recipient): string
    {
        return $recipient->getName() ?? $participant->guestName() ?? __('tournaments::messages.participants.unknown');
    }

    private function eventDate(string $tournamentId): ?string
    {
        $eventId = TournamentModel::query()->whereKey($tournamentId)->value('event_id');
        $start = $eventId !== null ? EventModel::query()->whereKey($eventId)->first(['start_date'])?->start_date : null;

        return $start?->format('d/m/Y H:i');
    }
}
