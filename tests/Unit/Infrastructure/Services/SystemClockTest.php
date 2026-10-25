<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Modules\Tournaments\Infrastructure\Services\SystemClock;
use Tests\TestCase;

final class SystemClockTest extends TestCase
{
    public function test_it_follows_the_test_clock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00', 'Europe/Madrid'));

        // The same instant whichever zone the clock reads it in
        $this->assertSame('2026-06-10T10:00:00+00:00', (new SystemClock)->now()->setTimezone(new DateTimeZone('UTC'))->format('c'));
    }

    public function test_it_reads_the_application_timezone(): void
    {
        $this->assertSame(config('app.timezone'), (new SystemClock)->now()->getTimezone()->getName());
    }
}
