<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

final readonly class ParticipantRegistered
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public ?string $userId,
        public ?string $guestEmail,
        public bool $isGuest,
        public ?string $cancellationToken,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $participantId,
        string $tournamentId,
        ?string $userId,
        ?string $guestEmail,
        bool $isGuest,
        ?string $cancellationToken = null,
    ): self {
        return new self(
            participantId: $participantId,
            tournamentId: $tournamentId,
            userId: $userId,
            guestEmail: $guestEmail,
            isGuest: $isGuest,
            cancellationToken: $cancellationToken,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
