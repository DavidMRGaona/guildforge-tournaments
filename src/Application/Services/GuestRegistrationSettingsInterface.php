<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Domain\Enums\GuestAccountPolicy;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;

/**
 * The guest registration settings of the module: the values saved in the panel over the shipped
 * defaults. Invalid values fall back to the defaults.
 */
interface GuestRegistrationSettingsInterface
{
    public function confirmationMode(): GuestConfirmationMode;

    /** Hours a guest has to confirm their email. */
    public function holdHours(): int;

    /** Unconfirmed guests holding a seat per tournament; further ones wait without a seat. */
    public function maxUnconfirmedHeld(): int;

    public function accountPolicy(): GuestAccountPolicy;

    /** Guest registrations accepted per IP per minute. */
    public function perMinute(): int;

    /** Guest registrations accepted per IP per hour. */
    public function perHour(): int;

    /** Hours a registration counts as a possible duplicate of another from the same device. */
    public function duplicateWindowHours(): int;
}
