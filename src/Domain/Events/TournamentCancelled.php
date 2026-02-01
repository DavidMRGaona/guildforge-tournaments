<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

final readonly class TournamentCancelled
{
    public function __construct(
        public string $tournamentId,
        public TournamentStatus $previousStatus,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $tournamentId,
        TournamentStatus $previousStatus,
    ): self {
        return new self(
            tournamentId: $tournamentId,
            previousStatus: $previousStatus,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
