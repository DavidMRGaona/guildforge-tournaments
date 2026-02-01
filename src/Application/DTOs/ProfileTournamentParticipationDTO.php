<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

/**
 * DTO for displaying tournament participation in user profiles and "My Tournaments" page.
 */
final readonly class ProfileTournamentParticipationDTO
{
    /**
     * @param  array{matchId: string, roundNumber: int, tableNumber: int|null, opponentId: string|null, opponentName: string|null, isBye: bool}|null  $nextMatch
     */
    public function __construct(
        // Tournament data
        public string $id,
        public string $name,
        public string $slug,
        public ?string $imagePublicId,
        public TournamentStatus $status,
        public ?DateTimeImmutable $startsAt,
        public ?string $eventName,
        public int $totalParticipants,
        // Participant data
        public ParticipantStatus $participantStatus,
        public string $participantId,
        // Standings data (null if tournament hasn't started)
        public ?int $position,
        public float $points,
        // Next match data (null if no pending match)
        public ?array $nextMatch,
        // Check-in data
        public bool $canCheckIn,
        public ?DateTimeImmutable $checkInDeadline,
        // Classification helper
        public bool $isUpcoming,
        public bool $isInProgress,
        public bool $isPast,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Tournament
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'imagePublicId' => $this->imagePublicId,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'statusColor' => $this->status->color(),
            'startsAt' => $this->startsAt?->format('c'),
            'eventName' => $this->eventName,
            'totalParticipants' => $this->totalParticipants,
            // Participant
            'participantId' => $this->participantId,
            'participantStatus' => $this->participantStatus->value,
            'participantStatusLabel' => $this->participantStatus->label(),
            'participantStatusColor' => $this->participantStatus->color(),
            // Standings
            'position' => $this->position,
            'points' => $this->points,
            // Next match
            'nextMatch' => $this->nextMatch,
            // Check-in
            'canCheckIn' => $this->canCheckIn,
            'checkInDeadline' => $this->checkInDeadline?->format('c'),
            // Classification
            'isUpcoming' => $this->isUpcoming,
            'isInProgress' => $this->isInProgress,
            'isPast' => $this->isPast,
        ];
    }
}
