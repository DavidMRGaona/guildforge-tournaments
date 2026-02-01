<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CannotWithdrawException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyWithdrawn(string $participantId): self
    {
        return new self("Participant {$participantId} has already withdrawn.");
    }

    public static function tournamentInProgress(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw while the tournament is in progress.");
    }

    public static function disqualified(string $participantId): self
    {
        return new self("Participant {$participantId} has been disqualified and cannot withdraw.");
    }
}
