<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

/**
 * The opponent disputed a reported result: the organization decides.
 */
final readonly class MatchResultDisputed
{
    public function __construct(
        public string $matchId,
        public string $tournamentId,
        public string $disputedByUserId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public static function create(string $matchId, string $tournamentId, string $disputedByUserId): self
    {
        return new self(
            matchId: $matchId,
            tournamentId: $tournamentId,
            disputedByUserId: $disputedByUserId,
            occurredAt: new DateTimeImmutable,
        );
    }
}
