<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

/**
 * A reported result became official: confirmed by the opponent or by the organization.
 */
final readonly class MatchResultConfirmed
{
    public function __construct(
        public string $matchId,
        public string $tournamentId,
        public string $confirmedByUserId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public static function create(string $matchId, string $tournamentId, string $confirmedByUserId): self
    {
        return new self(
            matchId: $matchId,
            tournamentId: $tournamentId,
            confirmedByUserId: $confirmedByUserId,
            occurredAt: new DateTimeImmutable,
        );
    }
}
