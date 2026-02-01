<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CannotGeneratePairingsException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function maxRoundsReached(string $tournamentId, int $maxRounds): self
    {
        return new self("Tournament {$tournamentId} has reached its maximum of {$maxRounds} rounds.");
    }

    public static function noValidPairingsFound(string $tournamentId): self
    {
        return new self("Cannot generate valid pairings for tournament {$tournamentId}. All possible pairings have been exhausted.");
    }

    public static function tournamentNotInProgress(string $tournamentId): self
    {
        return new self("Cannot generate pairings for tournament {$tournamentId} because it is not in progress.");
    }
}
