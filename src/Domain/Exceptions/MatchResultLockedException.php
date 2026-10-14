<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class MatchResultLockedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function laterRoundExists(string $matchId): self
    {
        return new self("Match {$matchId} belongs to a finished round followed by another one: its result can be corrected but not cleared.");
    }
}
