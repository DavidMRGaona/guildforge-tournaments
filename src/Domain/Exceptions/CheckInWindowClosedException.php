<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CheckInWindowClosedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notYetOpen(string $tournamentId): self
    {
        return new self("Check-in window is not yet open for tournament {$tournamentId}.");
    }

    public static function alreadyClosed(string $tournamentId): self
    {
        return new self("Check-in window has closed for tournament {$tournamentId}.");
    }

    public static function tournamentStarted(string $tournamentId): self
    {
        return new self("Check-in is no longer available as tournament {$tournamentId} has started.");
    }
}
