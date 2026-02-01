<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class ParticipantWithdrawn
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public ?string $userId,
        public ?string $participantEmail,
        public ?string $participantName,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $participantId,
        string $tournamentId,
        ?string $userId,
        ?string $participantEmail = null,
        ?string $participantName = null,
    ): self {
        return new self(
            participantId: $participantId,
            tournamentId: $tournamentId,
            userId: $userId,
            participantEmail: $participantEmail,
            participantName: $participantName,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
