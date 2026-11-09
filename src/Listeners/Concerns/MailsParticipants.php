<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners\Concerns;

use App\Application\Services\TimezoneServiceInterface;
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
    /**
     * @return UserModel|AnonymousNotifiable|null
     */
    private function recipient(Participant $participant): ?object
    {
        $userId = $participant->userId();

        if ($userId !== null) {
            $user = UserModel::find($userId);

            // An anonymised account keeps only a placeholder address
            return $user !== null && $user->email !== '' && $user->anonymized_at === null
                ? $user
                : null;
        }

        $email = $participant->guestEmail();

        if ($email === null || $email === '' || ! $participant->emailConfirmedAt() instanceof \DateTimeImmutable) {
            return null;
        }

        return new AnonymousNotifiable($email, $participant->guestName());
    }

    /**
     * The account to notify as an account (so the person's mail choices count), or a plain
     * address when there is no usable account: a guest or an anonymised one.
     */
    private function accountOrAddress(?string $userId, string $email, ?string $name): UserModel|AnonymousNotifiable
    {
        $user = $userId !== null ? UserModel::find($userId) : null;

        return $user !== null && $user->anonymized_at === null && $user->email !== ''
            ? $user
            : new AnonymousNotifiable($email, $name);
    }

    private function participantName(Participant $participant, UserModel|AnonymousNotifiable $recipient): string
    {
        $name = $recipient instanceof UserModel ? $recipient->name : $recipient->getName();

        return $name ?? $participant->guestName() ?? __('tournaments::messages.participants.unknown');
    }

    private function eventDate(string $tournamentId): ?string
    {
        $eventId = TournamentModel::query()->whereKey($tournamentId)->value('event_id');
        $start = $eventId !== null ? EventModel::query()->whereKey($eventId)->first(['start_date'])?->start_date : null;

        return $start !== null ? app(TimezoneServiceInterface::class)->format($start) : null;
    }
}
