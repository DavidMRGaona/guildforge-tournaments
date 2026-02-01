<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

final readonly class TournamentResponseDTO
{
    /**
     * @param  array<array{name: string, key: string, points: float}>  $scoreWeights
     * @param  array<string>  $tiebreakers
     * @param  array<string>  $allowedRoles
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $imagePublicId,
        public TournamentStatus $status,
        public ?int $maxRounds,
        public int $currentRound,
        public ?int $maxParticipants,
        public ?int $minParticipants,
        public int $participantCount,
        public array $scoreWeights,
        public array $tiebreakers,
        public bool $allowGuests,
        public bool $requiresManualConfirmation,
        public array $allowedRoles,
        public ResultReporting $resultReporting,
        public bool $requiresCheckIn,
        public ?int $checkInStartsBefore,
        public ?DateTimeImmutable $registrationOpensAt,
        public ?DateTimeImmutable $registrationClosesAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public bool $showParticipants = true,
        public string $notificationEmail = '',
        public bool $selfCheckInAllowed = false,
    ) {
    }

    public static function fromEntity(Tournament $tournament, int $participantCount = 0): self
    {
        return new self(
            id: $tournament->id()->value,
            eventId: $tournament->eventId(),
            name: $tournament->name(),
            slug: $tournament->slug(),
            description: $tournament->description(),
            imagePublicId: $tournament->imagePublicId(),
            status: $tournament->status(),
            maxRounds: $tournament->maxRounds(),
            currentRound: $tournament->currentRound(),
            maxParticipants: $tournament->maxParticipants(),
            minParticipants: $tournament->minParticipants(),
            participantCount: $participantCount,
            scoreWeights: array_map(fn ($sw) => $sw->toArray(), $tournament->scoreWeights()),
            tiebreakers: array_map(fn ($tb) => $tb->value, $tournament->tiebreakers()),
            allowGuests: $tournament->allowGuests(),
            requiresManualConfirmation: $tournament->requiresManualConfirmation(),
            allowedRoles: $tournament->allowedRoles(),
            resultReporting: $tournament->resultReporting(),
            requiresCheckIn: $tournament->requiresCheckIn(),
            checkInStartsBefore: $tournament->checkInStartsBefore(),
            registrationOpensAt: $tournament->registrationOpensAt(),
            registrationClosesAt: $tournament->registrationClosesAt(),
            startedAt: $tournament->startedAt(),
            completedAt: $tournament->completedAt(),
            createdAt: $tournament->createdAt() ?? new DateTimeImmutable(),
            updatedAt: $tournament->updatedAt() ?? new DateTimeImmutable(),
            showParticipants: $tournament->showParticipants(),
            notificationEmail: $tournament->notificationEmail(),
            selfCheckInAllowed: $tournament->selfCheckInAllowed(),
        );
    }

    public function isRegistrationOpen(): bool
    {
        return $this->status === TournamentStatus::RegistrationOpen;
    }

    public function isInProgress(): bool
    {
        return $this->status === TournamentStatus::InProgress;
    }

    public function isFinished(): bool
    {
        return $this->status === TournamentStatus::Finished;
    }

    public function hasCapacity(): bool
    {
        if ($this->maxParticipants === null) {
            return true;
        }

        return $this->participantCount < $this->maxParticipants;
    }

    public function recommendedRounds(): int
    {
        if ($this->participantCount <= 1) {
            return 1;
        }

        return (int) ceil(log($this->participantCount, 2));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_public_id' => $this->imagePublicId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'max_rounds' => $this->maxRounds,
            'current_round' => $this->currentRound,
            'max_participants' => $this->maxParticipants,
            'min_participants' => $this->minParticipants,
            'participant_count' => $this->participantCount,
            'score_weights' => $this->scoreWeights,
            'tiebreakers' => $this->tiebreakers,
            'allow_guests' => $this->allowGuests,
            'requires_manual_confirmation' => $this->requiresManualConfirmation,
            'allowed_roles' => $this->allowedRoles,
            'result_reporting' => $this->resultReporting->value,
            'requires_check_in' => $this->requiresCheckIn,
            'check_in_starts_before' => $this->checkInStartsBefore,
            'registration_opens_at' => $this->registrationOpensAt?->format('c'),
            'registration_closes_at' => $this->registrationClosesAt?->format('c'),
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
            'is_registration_open' => $this->isRegistrationOpen(),
            'is_in_progress' => $this->isInProgress(),
            'is_finished' => $this->isFinished(),
            'has_capacity' => $this->hasCapacity(),
            'recommended_rounds' => $this->recommendedRounds(),
            'show_participants' => $this->showParticipants,
            'notification_email' => $this->notificationEmail,
            'self_check_in_allowed' => $this->selfCheckInAllowed,
        ];
    }
}
