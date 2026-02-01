<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class AlreadyCheckedInException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forParticipant(string $participantId): self
    {
        return new self("Participant {$participantId} has already checked in.");
    }
}
