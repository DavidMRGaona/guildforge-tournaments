<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class TournamentNotOpenException extends DomainException
{
    private function __construct(string $message, private readonly bool $closedByStart = false)
    {
        parent::__construct($message);
    }

    public static function eventStarted(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} registration closed when its event started.", closedByStart: true);
    }

    /**
     * Whether registration is closed because the tournament's event has started.
     */
    public function eventHasStarted(): bool
    {
        return $this->closedByStart;
    }

    public static function notYetOpen(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} registration is not yet open.");
    }

    public static function alreadyClosed(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} registration has already closed.");
    }

    public static function inProgress(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} is already in progress.");
    }

    public static function finished(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} has finished.");
    }

    public static function cancelled(string $tournamentId): self
    {
        return new self("Tournament {$tournamentId} has been cancelled.");
    }
}
