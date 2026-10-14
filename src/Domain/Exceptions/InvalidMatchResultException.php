<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class InvalidMatchResultException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function byeOnlyForByeMatches(string $matchId): self
    {
        return new self("Match {$matchId} has two players and cannot be recorded as a bye.");
    }

    public static function byeMatchOnlyAcceptsBye(string $matchId): self
    {
        return new self("Match {$matchId} is a bye and only accepts the bye result.");
    }
}
