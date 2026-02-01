<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;

final class Participant
{
    public function __construct(
        private readonly ParticipantId $id,
        private readonly string $tournamentId,
        private ParticipantStatus $status,
        private readonly ?string $userId = null,
        private readonly ?string $guestName = null,
        private readonly ?string $guestEmail = null,
        private ?string $cancellationToken = null,
        private ?int $seed = null,
        private bool $hasReceivedBye = false,
        private readonly ?DateTimeImmutable $registeredAt = null,
        private ?DateTimeImmutable $checkedInAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): ParticipantId
    {
        return $this->id;
    }

    public function tournamentId(): string
    {
        return $this->tournamentId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function guestName(): ?string
    {
        return $this->guestName;
    }

    public function guestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function cancellationToken(): ?string
    {
        return $this->cancellationToken;
    }

    /**
     * Set the cancellation token for guest participants.
     */
    public function setCancellationToken(string $token): void
    {
        $this->cancellationToken = $token;
    }

    public function status(): ParticipantStatus
    {
        return $this->status;
    }

    public function seed(): ?int
    {
        return $this->seed;
    }

    public function hasReceivedBye(): bool
    {
        return $this->hasReceivedBye;
    }

    public function registeredAt(): ?DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function checkedInAt(): ?DateTimeImmutable
    {
        return $this->checkedInAt;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Check if this is a guest participant (no user account).
     */
    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * Get the display name for the participant.
     * For guests, returns the guest name. For users, returns null (name comes from User model).
     */
    public function displayName(): ?string
    {
        return $this->isGuest() ? $this->guestName : null;
    }

    /**
     * Check if the participant is in an active state.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if the participant can play matches.
     */
    public function canPlay(): bool
    {
        return $this->status->canPlay();
    }

    /**
     * Confirm the participant's registration.
     *
     * @throws InvalidStateTransitionException
     */
    public function confirm(): void
    {
        $this->transitionTo(ParticipantStatus::Confirmed);
    }

    /**
     * Check in the participant.
     *
     * @throws InvalidStateTransitionException
     */
    public function checkIn(): void
    {
        $this->transitionTo(ParticipantStatus::CheckedIn);
        $this->checkedInAt = new DateTimeImmutable();
    }

    /**
     * Withdraw the participant.
     *
     * @throws InvalidStateTransitionException
     */
    public function withdraw(): void
    {
        $this->transitionTo(ParticipantStatus::Withdrawn);
    }

    /**
     * Disqualify the participant.
     *
     * @throws InvalidStateTransitionException
     */
    public function disqualify(): void
    {
        $this->transitionTo(ParticipantStatus::Disqualified);
    }

    /**
     * Reactivate a withdrawn participant.
     *
     * @throws InvalidStateTransitionException
     */
    public function reactivate(): void
    {
        $this->transitionTo(ParticipantStatus::Registered);
        $this->checkedInAt = null;
    }

    /**
     * Mark that the participant has received a bye.
     */
    public function markByeReceived(): void
    {
        $this->hasReceivedBye = true;
    }

    /**
     * Set the participant's seed.
     */
    public function setSeed(int $seed): void
    {
        $this->seed = $seed;
    }

    /**
     * Transition to a new status with validation.
     *
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(ParticipantStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw InvalidStateTransitionException::participant(
                $this->id->value,
                $this->status->value,
                $newStatus->value
            );
        }

        $this->status = $newStatus;
    }
}
