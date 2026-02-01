<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class GuestRegistrationNotAllowedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function guestsNotAllowed(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} does not allow guest registration.");
    }
}
