<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

final readonly class TournamentResponseDTO
{
    public const string DISPLAY_REGISTRATION_UPCOMING = 'registration_upcoming';

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
        // Verdicts of the Tournament entity at build time (status, configured dates, event start)
        public bool $registrationOpen = false,
        public bool $canWithdraw = false,
        // What the status badge shows: the raw status, or `registration_upcoming` before the opening date
        public ?string $displayStatus = null,
        public ?DateTimeImmutable $eventStartsAt = null,
    ) {
    }

    public static function fromEntity(Tournament $tournament, int $participantCount, DateTimeImmutable $now): self
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
            registrationOpen: $tournament->isRegistrationOpen($now),
            canWithdraw: $tournament->canWithdraw($now),
            displayStatus: self::resolveDisplayStatus($tournament, $now),
            eventStartsAt: $tournament->eventStartsAt(),
        );
    }

    /**
     * An "open" tournament whose registration is not actually open shows as upcoming before the
     * opening date and as closed otherwise (closing date passed or event started).
     */
    private static function resolveDisplayStatus(Tournament $tournament, DateTimeImmutable $now): string
    {
        if ($tournament->status() !== TournamentStatus::RegistrationOpen || $tournament->isRegistrationOpen($now)) {
            return $tournament->status()->value;
        }

        $opensAt = $tournament->registrationOpensAt();

        return $opensAt instanceof \DateTimeImmutable && $now < $opensAt
            ? self::DISPLAY_REGISTRATION_UPCOMING
            : TournamentStatus::RegistrationClosed->value;
    }

    public function displayStatus(): string
    {
        return $this->displayStatus ?? $this->status->value;
    }

    public function displayStatusLabel(): string
    {
        if ($this->displayStatus() === self::DISPLAY_REGISTRATION_UPCOMING) {
            return (string) __('tournaments::messages.tournament_status.registration_upcoming');
        }

        return (TournamentStatus::tryFrom($this->displayStatus()) ?? $this->status)->label();
    }

    public function displayStatusColor(): string
    {
        if ($this->displayStatus() === self::DISPLAY_REGISTRATION_UPCOMING) {
            return 'info';
        }

        return (TournamentStatus::tryFrom($this->displayStatus()) ?? $this->status)->color();
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registrationOpen;
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
     * The notification email is left out: this array reaches the public pages.
     *
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
            'display_status' => $this->displayStatus(),
            'status_label' => $this->displayStatusLabel(),
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
            'can_withdraw' => $this->canWithdraw,
            'is_in_progress' => $this->isInProgress(),
            'is_finished' => $this->isFinished(),
            'has_capacity' => $this->hasCapacity(),
            'recommended_rounds' => $this->recommendedRounds(),
            'show_participants' => $this->showParticipants,
            'self_check_in_allowed' => $this->selfCheckInAllowed,
        ];
    }
}
