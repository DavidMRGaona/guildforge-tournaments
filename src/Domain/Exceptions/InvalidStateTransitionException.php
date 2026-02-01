<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class InvalidStateTransitionException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function tournament(string $tournamentId, string $fromStatus, string $toStatus): self
    {
        return new self("Cannot transition tournament {$tournamentId} from {$fromStatus} to {$toStatus}.");
    }

    public static function participant(string $participantId, string $fromStatus, string $toStatus): self
    {
        return new self("Cannot transition participant {$participantId} from {$fromStatus} to {$toStatus}.");
    }

    public static function round(string $roundId, string $fromStatus, string $toStatus): self
    {
        return new self("Cannot transition round {$roundId} from {$fromStatus} to {$toStatus}.");
    }
}
