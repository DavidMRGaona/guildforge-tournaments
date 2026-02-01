<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class TournamentFullException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function maxParticipantsReached(string $tournamentId, int $maxParticipants): self
    {
        return new self("Tournament {$tournamentId} has reached its maximum of {$maxParticipants} participants.");
    }
}
