<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

/**
 * The match no longer takes reports from its players.
 */
final class MatchReportClosedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function tournamentNotInProgress(string $matchId): self
    {
        return new self("The tournament of match {$matchId} is not in progress.");
    }

    public static function roundNotInProgress(string $matchId): self
    {
        return new self("The round of match {$matchId} is not in progress.");
    }

    public static function byeMatch(string $matchId): self
    {
        return new self("Match {$matchId} is a bye and takes no report.");
    }

    public static function resultAlreadyFinal(string $matchId): self
    {
        return new self("Match {$matchId} already has its result.");
    }

    public static function alreadyReportedByOpponent(string $matchId): self
    {
        return new self("The opponent already reported match {$matchId}: confirm or dispute it instead.");
    }
}
