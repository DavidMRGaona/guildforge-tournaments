<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

/**
 * A player reported the result of their match: official at once in a tournament that trusts the
 * players, or waiting for the opponent's confirmation.
 */
final readonly class MatchResultReported
{
    public function __construct(
        public string $matchId,
        public string $tournamentId,
        public string $reportedByUserId,
        public bool $requiresConfirmation,
        public DateTimeImmutable $occurredAt,
    ) {}

    public static function create(
        string $matchId,
        string $tournamentId,
        string $reportedByUserId,
        bool $requiresConfirmation,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            matchId: $matchId,
            tournamentId: $tournamentId,
            reportedByUserId: $reportedByUserId,
            requiresConfirmation: $requiresConfirmation,
            occurredAt: $occurredAt,
        );
    }
}
