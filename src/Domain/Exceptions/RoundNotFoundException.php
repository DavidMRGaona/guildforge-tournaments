<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class RoundNotFoundException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(string $id): self
    {
        return new self("Round with ID {$id} not found.");
    }

    public static function noCurrentRound(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} has no current round.");
    }
}
