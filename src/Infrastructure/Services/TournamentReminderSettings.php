<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

/**
 * The reminder before a tournament: off until an admin enables it in the module settings.
 */
final readonly class TournamentReminderSettings
{
    public const int DEFAULT_HOURS_BEFORE = 24;

    public const int MAX_HOURS_BEFORE = 168;

    public function enabled(): bool
    {
        return (bool) config('modules.settings.tournaments.reminders.enabled', false);
    }

    public function hoursBefore(): int
    {
        $hours = config('modules.settings.tournaments.reminders.hours_before');

        return is_numeric($hours) && (int) $hours >= 1
            ? min((int) $hours, self::MAX_HOURS_BEFORE)
            : self::DEFAULT_HOURS_BEFORE;
    }
}
