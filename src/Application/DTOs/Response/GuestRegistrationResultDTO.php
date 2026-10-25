<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;

final readonly class GuestRegistrationResultDTO
{
    public function __construct(
        public string $participantId,
        public string $tournamentId,
        public GuestRegistrationOutcome $outcome,
        public bool $seatHeld,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    /**
     * @return array{participant_id: string, tournament_id: string, outcome: string, seat_held: bool, expires_at: string|null}
     */
    public function toArray(): array
    {
        $timezone = app(TimezoneServiceInterface::class);

        return [
            'participant_id' => $this->participantId,
            'tournament_id' => $this->tournamentId,
            'outcome' => $this->outcome->value,
            'seat_held' => $this->seatHeld,
            'expires_at' => $timezone->iso($this->expiresAt),
        ];
    }
}
