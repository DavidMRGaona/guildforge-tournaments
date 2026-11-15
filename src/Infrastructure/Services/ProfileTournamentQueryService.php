<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tournaments\Application\DTOs\ProfileTournamentEntryDTO;
use Modules\Tournaments\Application\Services\ProfileTournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Every list is bounded and reads three queries: the tournaments, their event and the person's own row
 * of the standings, eager loaded.
 */
final readonly class ProfileTournamentQueryService implements ProfileTournamentQueryServiceInterface
{
    /**
     * The registrations that are still on: the ones the page lists among the participants.
     *
     * @var list<string>
     */
    private const array ACTIVE_REGISTRATIONS = [
        ParticipantStatus::Registered->value,
        ParticipantStatus::Confirmed->value,
        ParticipantStatus::CheckedIn->value,
    ];

    /**
     * Once started, the page shows the standings (everybody in them) whatever the organisation chose.
     *
     * @var list<string>
     */
    private const array STARTED = [
        TournamentStatus::InProgress->value,
        TournamentStatus::Finished->value,
    ];

    /**
     * @var list<string>
     */
    private const array NOT_STARTED = [
        TournamentStatus::RegistrationOpen->value,
        TournamentStatus::RegistrationClosed->value,
    ];

    /**
     * @var list<string>
     */
    private const array COMING_UP = [
        TournamentStatus::RegistrationOpen->value,
        TournamentStatus::RegistrationClosed->value,
        TournamentStatus::InProgress->value,
    ];

    public function current(string $ownerId, ?string $viewerId, int $limit): array
    {
        return $this->entries(
            $this->visibleTo($ownerId, $viewerId)
                ->whereIn('status', self::COMING_UP)
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [TournamentStatus::InProgress->value])
                ->tap(fn (Builder $query): Builder => $this->orderByEventStart($query, 'asc'))
                ->limit($limit),
            $ownerId,
        );
    }

    public function recent(string $ownerId, ?string $viewerId, int $limit): array
    {
        return $this->entries(
            $this->visibleTo($ownerId, $viewerId)
                ->where('status', TournamentStatus::Finished->value)
                ->tap(fn (Builder $query): Builder => $this->orderByEventStart($query, 'desc'))
                ->limit($limit),
            $ownerId,
        );
    }

    public function playedCount(string $ownerId, ?string $viewerId): int
    {
        return $this->visibleTo($ownerId, $viewerId)
            ->where('status', TournamentStatus::Finished->value)
            ->count();
    }

    /**
     * The tournaments the owner is in that the viewer may see the owner in, as the tournament's page
     * shows them:
     *
     * - Before it starts, the page lists the registrations only when the organisation shows its
     *   participants (the person themselves always sees their own).
     * - Once started, the page shows only the standings, whoever chose to hide the participants, and
     *   only the people in them: the ones who can play and anybody who already played, a withdrawn
     *   player included (StandingsService::rankedParticipants()). A registration that was never
     *   approved, or a tournament without rounds yet, is not in them. The person is shown when they
     *   have a row there, the very rows the page reads, so the rule is never copied.
     *
     * @return Builder<TournamentModel>
     */
    private function visibleTo(string $ownerId, ?string $viewerId): Builder
    {
        return TournamentModel::query()
            // Neither drafts nor cancelled tournaments: the public listing's rule
            ->publiclyListed()
            ->whereHas('event', static fn (Builder $event): Builder => $event->where('is_published', true))
            ->where(static fn (Builder $visible): Builder => $visible
                ->where(static fn (Builder $notStarted): Builder => $notStarted
                    ->whereIn('status', self::NOT_STARTED)
                    ->when($viewerId !== $ownerId, static fn (Builder $query): Builder => $query->where('show_participants', true))
                    ->whereHas('participants', static fn (Builder $participant): Builder => $participant
                        ->where('user_id', $ownerId)
                        ->whereIn('status', self::ACTIVE_REGISTRATIONS)))
                ->orWhere(static fn (Builder $started): Builder => $started
                    ->whereIn('status', self::STARTED)
                    ->whereHas('standings', static fn (Builder $standing): Builder => $standing->whereHas(
                        'participant',
                        static fn (Builder $participant): Builder => $participant->where('user_id', $ownerId),
                    ))));
    }

    /**
     * @param  Builder<TournamentModel>  $query
     * @param  'asc'|'desc'  $direction
     * @return Builder<TournamentModel>
     */
    private function orderByEventStart(Builder $query, string $direction): Builder
    {
        $tournaments = new TournamentModel;
        $events = new EventModel;

        return $query->orderBy(
            EventModel::query()
                ->select($events->qualifyColumn('start_date'))
                ->whereColumn($events->qualifyColumn('id'), $tournaments->qualifyColumn('event_id')),
            $direction,
        )->orderBy($tournaments->qualifyColumn('id'));
    }

    /**
     * @param  Builder<TournamentModel>  $query
     * @return list<ProfileTournamentEntryDTO>
     */
    private function entries(Builder $query, string $ownerId): array
    {
        $tournaments = $query
            ->with([
                'event:id,title,start_date',
                // The person's own row, nobody else's
                'standings' => static fn ($standings) => $standings->whereHas(
                    'participant',
                    static fn (Builder $participant): Builder => $participant->where('user_id', $ownerId),
                ),
            ])
            ->get();

        return array_values(
            $tournaments
                ->map(fn (TournamentModel $tournament): ProfileTournamentEntryDTO => $this->entry($tournament))
                ->all(),
        );
    }

    private function entry(TournamentModel $tournament): ProfileTournamentEntryDTO
    {
        $standing = $tournament->standings->first();

        return new ProfileTournamentEntryDTO(
            id: $tournament->id,
            name: $tournament->name,
            slug: $tournament->slug,
            status: $tournament->status,
            eventTitle: $tournament->event?->title,
            startsAt: $tournament->event?->start_date->toImmutable(),
            round: $tournament->status === TournamentStatus::InProgress && $tournament->current_round > 0
                ? $tournament->current_round
                : null,
            position: $standing !== null && $standing->rank > 0 ? $standing->rank : null,
            points: $standing?->points,
        );
    }
}
