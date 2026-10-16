<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CannotWithdrawException extends DomainException
{
    private function __construct(string $message, private readonly bool $closedByStart = false)
    {
        parent::__construct($message);
    }

    public static function tournamentStarted(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw once the tournament's event has started.", closedByStart: true);
    }

    public static function tournamentCancelled(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw from a cancelled tournament.");
    }

    public static function eventStartUnknown(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw: the start of the tournament's event is unknown.");
    }

    /**
     * Whether the withdrawal is refused because the tournament's event has started.
     */
    public function eventHasStarted(): bool
    {
        return $this->closedByStart;
    }

    public static function alreadyWithdrawn(string $participantId): self
    {
        return new self("Participant {$participantId} has already withdrawn.");
    }

    public static function tournamentInProgress(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw while the tournament is in progress.");
    }

    public static function tournamentFinished(string $participantId): self
    {
        return new self("Participant {$participantId} cannot withdraw from a tournament that has finished.");
    }

    public static function disqualified(string $participantId): self
    {
        return new self("Participant {$participantId} has been disqualified and cannot withdraw.");
    }
}
