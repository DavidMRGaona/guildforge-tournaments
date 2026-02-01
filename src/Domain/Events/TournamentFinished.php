<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class TournamentFinished
{
    public function __construct(
        public string $tournamentId,
        public int $roundsPlayed,
        public string $winnerId,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $tournamentId,
        int $roundsPlayed,
        string $winnerId,
    ): self {
        return new self(
            tournamentId: $tournamentId,
            roundsPlayed: $roundsPlayed,
            winnerId: $winnerId,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
