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

    public static function noAccount(string $matchId): self
    {
        return new self("A participant without an account cannot report the result of match {$matchId}.");
    }

    public static function notAllowedToPlay(string $matchId, string $userId): self
    {
        return new self("User {$userId} no longer plays in the tournament of match {$matchId}.");
    }

    public static function reporterCannotConfirm(string $matchId, string $userId): self
    {
        return new self("User {$userId} reported the result of match {$matchId} and cannot confirm or dispute it.");
    }

    public static function confirmationNotRequired(string $matchId, string $userId): self
    {
        return new self("User {$userId} cannot confirm or dispute match {$matchId}: the tournament trusts the reports.");
    }
}
