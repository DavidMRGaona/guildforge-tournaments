<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class PreviousRoundNotCompletedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function roundNotFinished(string $tournamentId, int $roundNumber): self
    {
        return new self("Round {$roundNumber} of tournament {$tournamentId} must be completed before generating the next round.");
    }

    public static function hasUnreportedMatches(string $roundId, int $unreportedCount): self
    {
        return new self("Round {$roundId} has {$unreportedCount} unreported matches and cannot be completed.");
    }
}
