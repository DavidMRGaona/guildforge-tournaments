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

    public static function noPendingReport(string $matchId): self
    {
        return new self("Match {$matchId} has no reported result waiting for confirmation.");
    }

    public static function alreadyDisputed(string $matchId): self
    {
        return new self("The result reported for match {$matchId} is already disputed.");
    }

    public static function reportChanged(string $matchId): self
    {
        return new self("The result reported for match {$matchId} changed since it was shown.");
    }

    public static function disputed(string $matchId): self
    {
        return new self("Match {$matchId} result has been disputed and requires admin intervention.");
    }
}
