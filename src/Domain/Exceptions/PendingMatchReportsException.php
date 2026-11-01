<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

/**
 * Results the players reported still wait for a confirmation or a decision: finishing would
 * freeze the standings without them.
 */
final class PendingMatchReportsException extends DomainException
{
    private function __construct(string $message, public readonly int $count)
    {
        parent::__construct($message);
    }

    public static function cannotFinish(string $tournamentId, int $count): self
    {
        return new self("Tournament {$tournamentId} has {$count} match reports waiting for a decision.", $count);
    }
}
