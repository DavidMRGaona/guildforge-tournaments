<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class ParticipantDisqualified
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public ?string $userId,
        public ?string $reason,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $participantId,
        string $tournamentId,
        ?string $userId,
        ?string $reason = null,
    ): self {
        return new self(
            participantId: $participantId,
            tournamentId: $tournamentId,
            userId: $userId,
            reason: $reason,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
