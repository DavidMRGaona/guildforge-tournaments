<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class UnauthorizedToReportResultException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function adminOnlyMode(string $matchId, string $userId): self
    {
        return new self("User {$userId} cannot report result for match {$matchId}. Only administrators can report results for this tournament.");
    }

    public static function notAParticipant(string $matchId, string $userId): self
    {
        return new self("User {$userId} is not a participant in match {$matchId} and cannot report its result.");
    }
}
