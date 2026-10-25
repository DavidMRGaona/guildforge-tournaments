<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use Carbon\CarbonImmutable;

/**
 * Stops the clock at a plain June noon before each test, so dates built from now() never land
 * on a daylight saving change or a midnight. Laravel calls setUpPinsTheClock() for test classes
 * that use the trait; travel() still moves the clock from there.
 */
trait PinsTheClock
{
    protected function setUpPinsTheClock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00', 'Europe/Madrid'));
    }
}
