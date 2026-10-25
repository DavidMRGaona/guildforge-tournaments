<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use DateTimeImmutable;

/**
 * Source of the current instant. Services read the time from here instead of creating a
 * DateTimeImmutable themselves, so tests can pin the clock to any instant, including the first
 * pass of the hour repeated when daylight saving time ends.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
