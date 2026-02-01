<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CheckInNotAllowedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function selfCheckInDisabled(string $tournamentId): self
    {
        return new self("Self check-in is not allowed for tournament {$tournamentId}.");
    }

    public static function checkInNotRequired(string $tournamentId): self
    {
        return new self("Check-in is not required for tournament {$tournamentId}.");
    }
}
