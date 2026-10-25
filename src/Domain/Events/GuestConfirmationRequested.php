<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;

/**
 * A guest registration needs its email confirmed: the confirmation link must be mailed.
 */
final readonly class GuestConfirmationRequested
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public string $guestEmail,
        public string $guestName,
        public string $confirmationToken,
        public ?DateTimeImmutable $expiresAt,
        public bool $seatHeld,
    ) {}
}
