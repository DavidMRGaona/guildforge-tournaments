<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tournaments\Application\DTOs\ProfileTournamentEntryDTO;
use Modules\Tournaments\Application\Services\ProfileTournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Every list is bounded and reads a constant number of queries: the tournaments with their event, and
 * the person's own registration with its standing, eager loaded.
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
                ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
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
     * The tournaments the owner is in that the viewer may see the owner in.
     *
     * @return Builder<TournamentModel>
     */
    private function visibleTo(string $ownerId, ?string $viewerId): Builder
    {
        return TournamentModel::query()
            // Neither drafts nor cancelled tournaments: the public listing's rule
            ->publiclyListed()
            ->whereHas('event', static fn (Builder $event): Builder => $event->where('is_published', true))
            ->whereHas('participants', static fn (Builder $participant): Builder => $participant
                ->where('user_id', $ownerId)
                ->whereIn('status', self::ACTIVE_REGISTRATIONS))
            ->when(
                $viewerId !== $ownerId,
                static fn (Builder $query): Builder => $query->where(
                    static fn (Builder $visible): Builder => $visible
                        ->whereIn('status', self::STARTED)
                        ->orWhere('show_participants', true),
                ),
            );
    }

    /**
     * @param  Builder<TournamentModel>  $query
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
                'participants' => static fn ($participants) => $participants
                    ->where('user_id', $ownerId)
                    ->whereIn('status', self::ACTIVE_REGISTRATIONS)
                    ->with('standing'),
            ])
            ->get();

        return $tournaments
            ->map(fn (TournamentModel $tournament): ProfileTournamentEntryDTO => $this->entry($tournament))
            ->values()
            ->all();
    }

    private function entry(TournamentModel $tournament): ProfileTournamentEntryDTO
    {
        $registration = $tournament->participants->first();
        $standing = $registration instanceof ParticipantModel && $this->hasStarted($tournament)
            ? $registration->standing
            : null;

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

    private function hasStarted(TournamentModel $tournament): bool
    {
        return in_array($tournament->status->value, self::STARTED, true);
    }
}
