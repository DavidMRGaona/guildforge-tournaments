<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;

/**
 * How a new guest registration waits for its email confirmation: holding a seat until a deadline,
 * or without a seat once the tournament already has as many unconfirmed guests holding one as the
 * settings allow (or when the settings never hold seats).
 */
final class GuestConfirmationTerms
{
    /**
     * The deadline of a held seat is now + the hold hours, capped by the effective registration
     * close (the configured close or the event start, whichever comes first).
     *
     * @param  int  $heldCount  unconfirmed guest registrations already holding a seat in the tournament
     * @return array{mode: GuestConfirmationMode, expiresAt: ?DateTimeImmutable}
     */
    public static function for(
        Tournament $tournament,
        int $heldCount,
        GuestRegistrationSettingsInterface $settings,
        CarbonImmutable $now,
    ): array {
        $mode = $settings->confirmationMode();

        if ($mode === GuestConfirmationMode::Hold && $heldCount >= $settings->maxUnconfirmedHeld()) {
            $mode = GuestConfirmationMode::Wait;
        }

        if ($mode === GuestConfirmationMode::Wait) {
            return ['mode' => $mode, 'expiresAt' => null];
        }

        $deadline = DateTimeImmutable::createFromInterface($now->addHours($settings->holdHours()));

        foreach ([$tournament->registrationClosesAt(), $tournament->eventStartsAt()] as $close) {
            if ($close !== null && $close < $deadline) {
                $deadline = $close;
            }
        }

        return ['mode' => $mode, 'expiresAt' => $deadline];
    }
}
