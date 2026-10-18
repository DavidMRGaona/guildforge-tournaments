<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;

final class Participant
{
    public function __construct(
        private readonly ParticipantId $id,
        private readonly string $tournamentId,
        private ParticipantStatus $status,
        private ?string $userId = null,
        private ?string $guestName = null,
        private ?string $guestEmail = null,
        private ?string $cancellationToken = null,
        private ?int $seed = null,
        private bool $hasReceivedBye = false,
        private readonly ?DateTimeImmutable $registeredAt = null,
        private ?DateTimeImmutable $checkedInAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private ?DateTimeImmutable $emailConfirmedAt = null,
        private ?DateTimeImmutable $confirmationExpiresAt = null,
        private ?string $confirmationToken = null,
        private ?DateTimeImmutable $confirmationSentAt = null,
        private ?GuestConfirmationMode $confirmationMode = null,
        private ?string $fingerprint = null,
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

    public function emailConfirmedAt(): ?DateTimeImmutable
    {
        return $this->emailConfirmedAt;
    }

    public function confirmationExpiresAt(): ?DateTimeImmutable
    {
        return $this->confirmationExpiresAt;
    }

    public function confirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function confirmationSentAt(): ?DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    public function confirmationMode(): ?GuestConfirmationMode
    {
        return $this->confirmationMode;
    }

    public function fingerprint(): ?string
    {
        return $this->fingerprint;
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
     * A guest whose registration is active but whose email is not confirmed yet.
     */
    public function isAwaitingEmailConfirmation(): bool
    {
        return $this->isGuest() && $this->isActive() && $this->emailConfirmedAt === null;
    }

    /**
     * Whether the registration counts against the tournament's capacity.
     */
    public function holdsSeat(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return ! ($this->isAwaitingEmailConfirmation() && $this->confirmationMode === GuestConfirmationMode::Wait);
    }

    /**
     * Begin (or restart) the email confirmation of a guest registration.
     */
    public function startEmailConfirmation(
        string $token,
        GuestConfirmationMode $mode,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $sentAt,
    ): void {
        $this->confirmationToken = $token;
        $this->confirmationMode = $mode;
        $this->confirmationExpiresAt = $expiresAt;
        $this->confirmationSentAt = $sentAt;
        $this->emailConfirmedAt = null;
    }

    public function markConfirmationSent(DateTimeImmutable $at): void
    {
        $this->confirmationSentAt = $at;
    }

    /**
     * Mark the guest's email as confirmed. The token is kept so a reused link can say it was already confirmed.
     *
     * @return bool false (and no change) when it was already confirmed
     */
    public function markEmailConfirmed(DateTimeImmutable $at): bool
    {
        if ($this->emailConfirmedAt !== null) {
            return false;
        }

        $this->emailConfirmedAt = $at;
        $this->confirmationExpiresAt = null;

        return true;
    }

    /**
     * Let the unconfirmed registration lapse.
     *
     * @throws InvalidStateTransitionException
     */
    public function expire(): void
    {
        $this->transitionTo(ParticipantStatus::Expired);
        $this->confirmationExpiresAt = null;
    }

    /**
     * Turn the guest registration into the one of a signed-in user.
     */
    public function claimForUser(string $userId, DateTimeImmutable $now): void
    {
        $this->userId = $userId;
        $this->guestName = null;
        $this->guestEmail = null;
        $this->cancellationToken = null;
        $this->confirmationToken = null;
        $this->confirmationExpiresAt = null;
        $this->emailConfirmedAt ??= $now;
    }

    /**
     * A guest signing up again after their registration ended gives their name anew.
     */
    public function renameGuest(string $name): void
    {
        $this->guestName = $name;
    }

    public function recordFingerprint(?string $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
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
     * Reactivate a withdrawn or expired participant.
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
