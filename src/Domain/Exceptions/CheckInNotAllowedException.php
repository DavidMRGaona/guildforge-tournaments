<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class CheckInNotAllowedException extends DomainException
{
    private function __construct(string $message, private readonly bool $unconfirmedEmail = false)
    {
        parent::__construct($message);
    }

    public static function selfCheckInDisabled(string $tournamentId): self
    {
        return new self("Self check-in is not allowed for tournament {$tournamentId}.");
    }

    public static function checkInNotRequired(string $tournamentId): self
    {
        return new self("Check-in is not required for tournament {$tournamentId}.");
    }

    /**
     * A guest registration still awaiting its email confirmation: knowing the email proves nothing.
     */
    public static function emailNotConfirmed(string $tournamentId): self
    {
        return new self("The registration found for this email in tournament {$tournamentId} has not confirmed its email yet.", unconfirmedEmail: true);
    }

    /**
     * Whether the check-in is refused because the registration has not confirmed its email.
     */
    public function emailIsUnconfirmed(): bool
    {
        return $this->unconfirmedEmail;
    }
}
