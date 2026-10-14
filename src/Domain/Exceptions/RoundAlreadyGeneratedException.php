<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class RoundAlreadyGeneratedException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function meanwhile(string $tournamentId): self
    {
        return new self("The next round of tournament {$tournamentId} was generated meanwhile.");
    }
}
