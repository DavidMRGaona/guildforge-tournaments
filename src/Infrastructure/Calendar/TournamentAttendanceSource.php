<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Calendar;

use App\Application\Calendar\Contracts\CalendarAttendanceSourceInterface;
use App\Application\Calendar\DTOs\CalendarEntryReferenceDTO;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * The tournaments a person plays in or organizes, for their personal calendar (core ≥ 3.38).
 *
 * A tournament has no calendar entry of its own: it belongs to one core event, so the reference
 * points at `('event', $eventId)` and the core keeps it only if the public calendar shows that event.
 * Players count once confirmed or checked in (a pending, withdrawn, disqualified or expired
 * registration does not) and only with an account; organizers count as running it.
 */
final readonly class TournamentAttendanceSource implements CalendarAttendanceSourceInterface
{
    /** Source type of the core's events in the public calendar. */
    private const string EVENT_SOURCE_TYPE = 'event';

    /**
     * @return array<CalendarEntryReferenceDTO>
     */
    public function attendedEntries(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return TournamentModel::query()
            ->whereHas('event', static fn (Builder $event): Builder => $event
                ->where('start_date', '<', $to)
                ->where('end_date', '>', $from))
            ->where(static fn (Builder $takesPart): Builder => $takesPart
                ->whereHas('participants', static fn (Builder $participant): Builder => $participant
                    ->where('user_id', $userId)
                    ->whereIn('status', [ParticipantStatus::Confirmed->value, ParticipantStatus::CheckedIn->value]))
                ->orWhereHas('organizers', static fn (Builder $organizer): Builder => $organizer
                    ->where('users.id', $userId)))
            ->distinct()
            ->pluck('event_id')
            ->map(static fn (string $eventId): CalendarEntryReferenceDTO => new CalendarEntryReferenceDTO(self::EVENT_SOURCE_TYPE, $eventId))
            ->all();
    }
}
