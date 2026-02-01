<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class UserNotAllowedToRegisterException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string>  $allowedRoles
     */
    public static function missingRequiredRole(string $tournamentId, string $userId, array $allowedRoles): self
    {
        $rolesString = implode(', ', $allowedRoles);

        return new self("User {$userId} does not have any of the required roles ({$rolesString}) to register for tournament {$tournamentId}.");
    }
}
