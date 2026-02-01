<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class RoundStarted
{
    public function __construct(
        public string $roundId,
        public string $tournamentId,
        public int $roundNumber,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $roundId,
        string $tournamentId,
        int $roundNumber,
    ): self {
        return new self(
            roundId: $roundId,
            tournamentId: $tournamentId,
            roundNumber: $roundNumber,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
