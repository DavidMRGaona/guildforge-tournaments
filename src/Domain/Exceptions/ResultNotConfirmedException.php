<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class ResultNotConfirmedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function awaitingConfirmation(string $matchId): self
    {
        return new self("Match {$matchId} result is awaiting confirmation from the opponent.");
    }

    public static function disputed(string $matchId): self
    {
        return new self("Match {$matchId} result has been disputed and requires admin intervention.");
    }
}
