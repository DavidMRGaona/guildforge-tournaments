<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class ParticipantNotFoundException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(string $id): self
    {
        return new self("Participant with ID {$id} not found.");
    }

    public static function forUserInTournament(string $userId, string $tournamentId): self
    {
        return new self("User {$userId} is not registered in tournament {$tournamentId}.");
    }

    public static function withEmail(string $email, string $tournamentId): self
    {
        return new self("No participant found with email {$email} in tournament {$tournamentId}.");
    }

    public static function byToken(string $token): self
    {
        return new self("No participant found with cancellation token {$token}.");
    }
}
