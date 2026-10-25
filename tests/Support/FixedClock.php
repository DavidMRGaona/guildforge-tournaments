<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Services\Clock;

/**
 * A clock stopped at one instant. Carbon's test clock cannot hold the first pass of the hour
 * repeated in October (it reads 02:15 CEST back as 02:15 CET), so tests that need it bind this one.
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
