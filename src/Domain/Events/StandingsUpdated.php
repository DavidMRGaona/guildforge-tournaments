<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class StandingsUpdated
{
    /**
     * @param  array<string>  $topParticipantIds  IDs of top 3 participants
     */
    public function __construct(
        public string $tournamentId,
        public int $afterRound,
        public array $topParticipantIds,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @param  array<string>  $topParticipantIds
     */
    public static function create(
        string $tournamentId,
        int $afterRound,
        array $topParticipantIds,
    ): self {
        return new self(
            tournamentId: $tournamentId,
            afterRound: $afterRound,
            topParticipantIds: $topParticipantIds,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
