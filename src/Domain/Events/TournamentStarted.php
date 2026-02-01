<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class TournamentStarted
{
    public function __construct(
        public string $tournamentId,
        public int $participantCount,
        public int $plannedRounds,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $tournamentId,
        int $participantCount,
        int $plannedRounds,
    ): self {
        return new self(
            tournamentId: $tournamentId,
            participantCount: $participantCount,
            plannedRounds: $plannedRounds,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
