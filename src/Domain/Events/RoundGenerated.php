<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class RoundGenerated
{
    public function __construct(
        public string $roundId,
        public string $tournamentId,
        public int $roundNumber,
        public int $matchCount,
        public bool $hasBye,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $roundId,
        string $tournamentId,
        int $roundNumber,
        int $matchCount,
        bool $hasBye,
    ): self {
        return new self(
            roundId: $roundId,
            tournamentId: $tournamentId,
            roundNumber: $roundNumber,
            matchCount: $matchCount,
            hasBye: $hasBye,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
