<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registration can never close after the event starts: the start closes it anyway, so a later
 * date would only mislead. Shared by the tournament form and the event's tournament tab.
 *
 * It never throws: when it cannot compute, it skips and the fields' own validation reports.
 */
final class RegistrationClosingDateRule
{
    /**
     * Validation closure for `registration_closes_at`; without a known start there is nothing to compare.
     */
    public static function notAfter(?CarbonInterface $eventStartsAt): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($eventStartsAt): void {
            if (! $eventStartsAt instanceof CarbonInterface) {
                return;
            }

            try {
                $closesAt = match (true) {
                    $value instanceof DateTimeInterface => Carbon::instance($value),
                    is_string($value) && $value !== '' => Carbon::parse($value),
                    default => null,
                };
            } catch (Throwable) {
                // Not a date: the picker's own validation reports it
                return;
            }

            if ($closesAt instanceof Carbon && $closesAt->greaterThan($eventStartsAt)) {
                $fail(__('tournaments::messages.validation.registration_closes_after_start', [
                    'date' => $eventStartsAt->format('d/m/Y H:i'),
                ]));
            }
        };
    }

    /**
     * Start of the chosen event, or null while no (valid, existing) event is chosen.
     */
    public static function eventStartsAt(mixed $eventId): ?CarbonInterface
    {
        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            return null;
        }

        try {
            return EventModel::query()->find($eventId, ['id', 'start_date'])?->start_date;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Same as {@see eventStartsAt()}, reading the event id lazily so a failing form-state read is also contained.
     *
     * @param  Closure(): mixed  $readEventId
     */
    public static function eventStartsAtFrom(Closure $readEventId): ?CarbonInterface
    {
        try {
            return self::eventStartsAt($readEventId());
        } catch (Throwable) {
            return null;
        }
    }
}
