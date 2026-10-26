<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

/**
 * The organisation approved a registration that was waiting for it.
 */
final readonly class ParticipantConfirmed
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public ?string $userId,
        public ?string $notes,
        public DateTimeImmutable $occurredAt,
    ) {}

    public static function create(
        string $participantId,
        string $tournamentId,
        ?string $userId,
        ?string $notes = null,
    ): self {
        return new self(
            participantId: $participantId,
            tournamentId: $tournamentId,
            userId: $userId,
            notes: $notes,
            occurredAt: new DateTimeImmutable,
        );
    }
}
