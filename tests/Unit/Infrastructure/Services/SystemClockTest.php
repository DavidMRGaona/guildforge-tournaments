<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Modules\Tournaments\Infrastructure\Services\SystemClock;
use Tests\TestCase;

final class SystemClockTest extends TestCase
{
    public function test_it_follows_the_test_clock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00', 'Europe/Madrid'));

        $this->assertSame('2026-06-10T12:00:00+02:00', (new SystemClock)->now()->format('c'));
    }

    public function test_it_reads_the_application_timezone(): void
    {
        $this->assertSame('Europe/Madrid', (new SystemClock)->now()->getTimezone()->getName());
    }
}
