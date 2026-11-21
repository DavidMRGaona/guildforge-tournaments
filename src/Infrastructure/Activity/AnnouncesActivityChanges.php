<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Activity;

use App\Application\Activity\Services\ActivityPublisherInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Pivots\TournamentOrganizerPivot;

/**
 * Tells the core (ActivityPublisherInterface, core#774) whenever the facts of a finished
 * tournament may have changed, whatever made the change (services, panel, commands), so whoever
 * keeps a record of them reads them again:
 *
 * - a tournament saved as finished (finishing it, or a new end or game profile) or that stops
 *   being finished (hidden, cancelled or reopened), and one deleted while finished;
 * - its standings calculated again after the end, which is how a corrected result or a changed
 *   table reaches the facts (StandingsService);
 * - a participant saved with another status or account, or removed, on a finished tournament
 *   (withdrawn, disqualified, reinstated);
 * - its organizers added or removed;
 * - its event published or unpublished (it is deleted with the tournament, which is its own case);
 * - a game profile renamed or deleted, since facts carry its name;
 * - an account deleted for good (its registrations and organizer places go with it, through the
 *   foreign keys, without model events).
 *
 * The publisher announces after the commit, once per subject and transaction; publishing a
 * subject whose facts did not change is harmless. Changes to tournaments that are not finished
 * say nothing: they have no facts.
 */
final class AnnouncesActivityChanges
{
    /**
     * Finished tournaments an account, or a game profile, takes part in until it is gone: afterwards
     * the foreign keys have already dropped the link.
     *
     * @var array<string, list<string>>
     */
    private static array $tournamentsOfDeleted = [];

    public static function register(): void
    {
        TournamentModel::saved(static function (TournamentModel $tournament): void {
            $isFinished = $tournament->status === TournamentStatus::Finished;
            $wasFinished = $tournament->wasChanged('status') && $tournament->getOriginal('status') === TournamentStatus::Finished;

            if ($wasFinished || ($isFinished && ($tournament->wasRecentlyCreated || $tournament->wasChanged(['status', 'game_profile_id', 'completed_at'])))) {
                self::tournament((string) $tournament->getKey());
            }
        });
        TournamentModel::deleted(static function (TournamentModel $tournament): void {
            if ($tournament->status === TournamentStatus::Finished) {
                self::tournament((string) $tournament->getKey());
            }
        });

        ParticipantModel::saved(static function (ParticipantModel $participant): void {
            if ($participant->wasRecentlyCreated || $participant->wasChanged(['status', 'user_id'])) {
                self::tournamentIfFinished($participant->tournament_id);
            }
        });
        ParticipantModel::deleted(static fn (ParticipantModel $participant) => self::tournamentIfFinished($participant->tournament_id));

        TournamentOrganizerPivot::created(static fn (TournamentOrganizerPivot $pivot) => self::tournamentIfFinished($pivot->tournament_id));
        TournamentOrganizerPivot::deleted(static fn (TournamentOrganizerPivot $pivot) => self::tournamentIfFinished($pivot->tournament_id));

        EventModel::saved(static function (EventModel $event): void {
            if ($event->wasChanged('is_published')) {
                self::announce(self::finishedTournamentsWhere(static fn ($query) => $query->where('event_id', $event->getKey())));
            }
        });

        GameProfileModel::saved(static function (GameProfileModel $profile): void {
            if ($profile->wasChanged('name')) {
                self::announce(self::finishedTournamentsOfProfile((string) $profile->getKey()));
            }
        });
        GameProfileModel::deleting(static function (GameProfileModel $profile): void {
            self::$tournamentsOfDeleted['profile|'.$profile->getKey()] = self::finishedTournamentsOfProfile((string) $profile->getKey());
        });
        GameProfileModel::deleted(static fn (GameProfileModel $profile) => self::announceDeleted('profile|'.$profile->getKey()));

        UserModel::forceDeleting(static function (UserModel $user): void {
            self::$tournamentsOfDeleted['account|'.$user->id] = self::finishedTournamentsOf((string) $user->id);
        });
        UserModel::forceDeleted(static fn (UserModel $user) => self::announceDeleted('account|'.$user->id));
    }

    /**
     * One tournament's facts may have changed.
     */
    public static function tournament(string $tournamentId): void
    {
        app(ActivityPublisherInterface::class)->recorded(TournamentActivitySource::TYPE, $tournamentId);
    }

    public static function tournamentIfFinished(string $tournamentId): void
    {
        if (TournamentModel::query()->whereKey($tournamentId)->where('status', TournamentStatus::Finished->value)->exists()) {
            self::tournament($tournamentId);
        }
    }

    /**
     * @param  list<string>  $tournamentIds
     */
    private static function announce(array $tournamentIds): void
    {
        foreach ($tournamentIds as $tournamentId) {
            self::tournament($tournamentId);
        }
    }

    private static function announceDeleted(string $key): void
    {
        $tournamentIds = self::$tournamentsOfDeleted[$key] ?? [];
        unset(self::$tournamentsOfDeleted[$key]);

        self::announce($tournamentIds);
    }

    /**
     * @param  callable(\Illuminate\Database\Eloquent\Builder<TournamentModel>): mixed  $constraint
     * @return list<string>
     */
    private static function finishedTournamentsWhere(callable $constraint): array
    {
        $query = TournamentModel::query()->where('status', TournamentStatus::Finished->value);
        $constraint($query);

        return array_values($query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @return list<string>
     */
    private static function finishedTournamentsOfProfile(string $profileId): array
    {
        return self::finishedTournamentsWhere(static fn ($query) => $query->where('game_profile_id', $profileId));
    }

    /**
     * The finished tournaments an account played or organized.
     *
     * @return list<string>
     */
    private static function finishedTournamentsOf(string $userId): array
    {
        return self::finishedTournamentsWhere(static fn ($query) => $query->where(static fn ($any) => $any
            ->whereHas('participants', static fn ($participant) => $participant->where('user_id', $userId))
            ->orWhereHas('organizers', static fn ($organizer) => $organizer->where('users.id', $userId))));
    }
}
