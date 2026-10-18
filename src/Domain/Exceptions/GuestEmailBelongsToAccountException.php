<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

/**
 * A guest used the email of an account that could register as a user: they must sign in.
 */
final class GuestEmailBelongsToAccountException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forTournament(string $tournamentId): self
    {
        return new self("The guest email belongs to an account that must sign in to register for tournament {$tournamentId}.");
    }
}
