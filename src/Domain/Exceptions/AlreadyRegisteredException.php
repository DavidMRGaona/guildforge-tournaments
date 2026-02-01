<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class AlreadyRegisteredException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function userAlreadyRegistered(string $tournamentId, string $userId): self
    {
        return new self("User {$userId} is already registered for tournament {$tournamentId}.");
    }

    public static function guestWithEmailAlreadyRegistered(string $tournamentId, string $email): self
    {
        return new self("A guest with email {$email} is already registered for tournament {$tournamentId}.");
    }
}
