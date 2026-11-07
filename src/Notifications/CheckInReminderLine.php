<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

/**
 * The line of a participant email about the check-in: when it opens and, when participants
 * check in themselves, the link to its page (a guest has no other way to reach it).
 */
final class CheckInReminderLine
{
    private const int DEFAULT_MINUTES_BEFORE = 30;

    /**
     * @param  bool|null  $selfCheckInAllowed  Null for an email queued before it was known: the old reminder
     */
    public static function for(?int $minutesBefore, ?bool $selfCheckInAllowed, ?string $tournamentSlug): string
    {
        $minutes = $minutesBefore ?? self::DEFAULT_MINUTES_BEFORE;

        return match (true) {
            $selfCheckInAllowed === true && $tournamentSlug !== null => __('tournaments::messages.emails.check_in.self', [
                'minutes' => $minutes,
                'url' => route('tournaments.check-in.show', $tournamentSlug),
            ]),
            $selfCheckInAllowed === false => __('tournaments::messages.emails.check_in.at_the_venue', ['minutes' => $minutes]),
            default => __('tournaments::messages.emails.registration_confirmed.check_in_required', ['minutes' => $minutes]),
        };
    }
}
