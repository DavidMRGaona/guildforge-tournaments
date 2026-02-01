<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class InsufficientParticipantsException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function belowMinimum(string $tournamentId, int $current, int $minimum): self
    {
        return new self("Tournament {$tournamentId} has only {$current} participants but requires at least {$minimum} to start.");
    }

    public static function noActiveParticipants(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} has no active participants.");
    }
}
