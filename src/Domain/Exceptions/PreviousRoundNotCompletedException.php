<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Exceptions;

use DomainException;

final class PreviousRoundNotCompletedException extends DomainException
{
    private function __construct(
        string $message,
        private readonly int $unreportedMatches = 0,
        private readonly int $pendingReports = 0,
    ) {
        parent::__construct($message);
    }

    public static function roundNotFinished(string $tournamentId, int $roundNumber): self
    {
        return new self("Round {$roundNumber} of tournament {$tournamentId} must be completed before generating the next round.");
    }

    public static function hasUnreportedMatches(string $roundId, int $unreportedCount, int $pendingReports = 0): self
    {
        return new self("Round {$roundId} has {$unreportedCount} unreported matches and cannot be completed.", $unreportedCount, $pendingReports);
    }

    /**
     * Matches of the round still without result (0 when the round itself is not finished).
     */
    public function unreportedMatches(): int
    {
        return $this->unreportedMatches;
    }

    /**
     * Of those, how many have a players' report waiting for confirmation (or disputed).
     */
    public function pendingReports(): int
    {
        return $this->pendingReports;
    }
}
