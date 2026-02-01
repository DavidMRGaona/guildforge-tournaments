<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class TournamentCreated
{
    public function __construct(
        public string $tournamentId,
        public string $eventId,
        public string $name,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $tournamentId,
        string $eventId,
        string $name,
    ): self {
        return new self(
            tournamentId: $tournamentId,
            eventId: $eventId,
            name: $name,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
