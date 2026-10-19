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

    /**
     * The email is left out: exception messages reach the logs.
     */
    public static function byEmail(string $tournamentId): self
    {
        return new self("No active participant found with the given email in tournament {$tournamentId}.");
    }

    /**
     * The token is left out: it is a secret and exception messages reach the logs.
     */
    public static function byToken(): self
    {
        return new self('No participant found with the given cancellation token.');
    }
}
