<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\RoundId;

final class Round
{
    public function __construct(
        private readonly RoundId $id,
        private readonly string $tournamentId,
        private readonly int $roundNumber,
        private RoundStatus $status,
        private ?DateTimeImmutable $startedAt = null,
        private ?DateTimeImmutable $completedAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): RoundId
    {
        return $this->id;
    }

    public function tournamentId(): string
    {
        return $this->tournamentId;
    }

    public function roundNumber(): int
    {
        return $this->roundNumber;
    }

    public function status(): RoundStatus
    {
        return $this->status;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
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
     * Check if the round is currently active (in progress).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if the round has finished.
     */
    public function isFinished(): bool
    {
        return $this->status === RoundStatus::Finished;
    }

    /**
     * Start the round.
     *
     * @throws InvalidStateTransitionException
     */
    public function start(): void
    {
        $this->transitionTo(RoundStatus::InProgress);
        $this->startedAt = new DateTimeImmutable();
    }

    /**
     * Complete the round.
     *
     * @throws InvalidStateTransitionException
     */
    public function complete(): void
    {
        $this->transitionTo(RoundStatus::Finished);
        $this->completedAt = new DateTimeImmutable();
    }

    /**
     * Transition to a new status with validation.
     *
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(RoundStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw InvalidStateTransitionException::round(
                $this->id->value,
                $this->status->value,
                $newStatus->value
            );
        }

        $this->status = $newStatus;
    }
}
