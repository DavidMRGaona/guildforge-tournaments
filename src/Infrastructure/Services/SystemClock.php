<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Tournaments\Domain\Services\Clock;

/**
 * The real clock. Read through Carbon so travelTo() in tests moves it too.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now()->toDateTimeImmutable();
    }
}
