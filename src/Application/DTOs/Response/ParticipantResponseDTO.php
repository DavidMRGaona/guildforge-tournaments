<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;

final readonly class ParticipantResponseDTO
{
    public function __construct(
        public string $id,
        public string $tournamentId,
        public ?string $userId,
        public ?string $userName,
        public ?string $userEmail,
        public ?string $guestName,
        public ?string $guestEmail,
        public ParticipantStatus $status,
        public ?int $seed,
        public bool $hasReceivedBye,
        public DateTimeImmutable $registeredAt,
        public ?DateTimeImmutable $checkedInAt,
    ) {
    }

    public static function fromEntity(
        Participant $participant,
        ?string $userName = null,
        ?string $userEmail = null,
    ): self {
        return new self(
            id: $participant->id()->value,
            tournamentId: $participant->tournamentId(),
            userId: $participant->userId(),
            userName: $userName,
            userEmail: $userEmail,
            guestName: $participant->guestName(),
            guestEmail: $participant->guestEmail(),
            status: $participant->status(),
            seed: $participant->seed(),
            hasReceivedBye: $participant->hasReceivedBye(),
            registeredAt: $participant->registeredAt() ?? new DateTimeImmutable(),
            checkedInAt: $participant->checkedInAt(),
        );
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public function displayName(): string
    {
        return $this->userName ?? $this->guestName ?? '';
    }

    public function isCheckedIn(): bool
    {
        return $this->checkedInAt !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournamentId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'user_email' => $this->userEmail,
            'guest_name' => $this->guestName,
            'guest_email' => $this->guestEmail,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'seed' => $this->seed,
            'has_received_bye' => $this->hasReceivedBye,
            'registered_at' => $this->registeredAt->format('c'),
            'checked_in_at' => $this->checkedInAt?->format('c'),
            'display_name' => $this->displayName(),
            'is_guest' => $this->isGuest(),
            'is_checked_in' => $this->isCheckedIn(),
        ];
    }
}
