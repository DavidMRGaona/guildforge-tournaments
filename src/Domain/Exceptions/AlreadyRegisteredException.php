<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class AlreadyRegisteredException extends DomainException
{
    private function __construct(string $message, private readonly bool $accountEmailTaken = false)
    {
        parent::__construct($message);
    }

    public static function userAlreadyRegistered(string $tournamentId, string $userId): self
    {
        return new self("User {$userId} is already registered for tournament {$tournamentId}.");
    }

    public static function disqualified(string $tournamentId, string $participantId): self
    {
        return new self("Participant {$participantId} was disqualified from tournament {$tournamentId} and cannot register again.");
    }

    /**
     * The message names the registration involved, never the email: exception messages reach the logs.
     */
    public static function guestWithEmailAlreadyRegistered(string $tournamentId, string $participantId): self
    {
        return new self("An active guest registration with the email of participant {$participantId} already exists for tournament {$tournamentId}.");
    }

    public static function guestWithAccountEmail(string $tournamentId): self
    {
        return new self("A registration with the account email already exists for tournament {$tournamentId}.", accountEmailTaken: true);
    }

    /**
     * Whether a guest registration with the email of the signed-in account blocks it (the account
     * could not take it over), rather than a registration of the account itself.
     */
    public function isAccountEmailTaken(): bool
    {
        return $this->accountEmailTaken;
    }
}
