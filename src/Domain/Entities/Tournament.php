<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

final class Tournament
{
    /**
     * @param  array<ScoreWeight>  $scoreWeights
     * @param  array<Tiebreaker>  $tiebreakers
     * @param  array<string>  $allowedRoles
     * @param  array<StatDefinition>|null  $statDefinitions
     * @param  array<ScoringRule>|null  $scoringRules
     * @param  array<TiebreakerDefinition>|null  $tiebreakerConfig
     */
    public function __construct(
        private readonly TournamentId $id,
        private readonly string $eventId,
        private string $name,
        private string $slug,
        private TournamentStatus $status,
        private array $scoreWeights,
        private array $tiebreakers,
        private ResultReporting $resultReporting,
        private ?string $description = null,
        private ?string $imagePublicId = null,
        private ?int $maxRounds = null,
        private int $currentRound = 0,
        private ?int $maxParticipants = null,
        private int $minParticipants = 2,
        private ?DateTimeImmutable $registrationOpensAt = null,
        private ?DateTimeImmutable $registrationClosesAt = null,
        private bool $requiresCheckIn = false,
        private ?int $checkInStartsBefore = null,
        private bool $allowGuests = false,
        private bool $requiresManualConfirmation = false,
        private array $allowedRoles = [],
        private ?DateTimeImmutable $startedAt = null,
        private ?DateTimeImmutable $completedAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private readonly ?string $gameProfileId = null,
        private readonly ?array $statDefinitions = null,
        private readonly ?array $scoringRules = null,
        private readonly ?array $tiebreakerConfig = null,
        private readonly ?PairingConfig $pairingConfig = null,
        private readonly bool $showParticipants = true,
        private readonly string $notificationEmail = '',
        private readonly bool $selfCheckInAllowed = false,
    ) {
    }

    public function id(): TournamentId
    {
        return $this->id;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function imagePublicId(): ?string
    {
        return $this->imagePublicId;
    }

    public function status(): TournamentStatus
    {
        return $this->status;
    }

    public function maxRounds(): ?int
    {
        return $this->maxRounds;
    }

    public function currentRound(): int
    {
        return $this->currentRound;
    }

    public function maxParticipants(): ?int
    {
        return $this->maxParticipants;
    }

    public function minParticipants(): int
    {
        return $this->minParticipants;
    }

    public function registrationOpensAt(): ?DateTimeImmutable
    {
        return $this->registrationOpensAt;
    }

    public function registrationClosesAt(): ?DateTimeImmutable
    {
        return $this->registrationClosesAt;
    }

    public function requiresCheckIn(): bool
    {
        return $this->requiresCheckIn;
    }

    public function checkInStartsBefore(): ?int
    {
        return $this->checkInStartsBefore;
    }

    public function allowGuests(): bool
    {
        return $this->allowGuests;
    }

    public function requiresManualConfirmation(): bool
    {
        return $this->requiresManualConfirmation;
    }

    /**
     * @return array<string>
     */
    public function allowedRoles(): array
    {
        return $this->allowedRoles;
    }

    /**
     * @return array<ScoreWeight>
     */
    public function scoreWeights(): array
    {
        return $this->scoreWeights;
    }

    /**
     * @return array<Tiebreaker>
     */
    public function tiebreakers(): array
    {
        return $this->tiebreakers;
    }

    public function resultReporting(): ResultReporting
    {
        return $this->resultReporting;
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
     * Open registration for the tournament.
     *
     * @throws InvalidStateTransitionException
     */
    public function openRegistration(): void
    {
        $this->transitionTo(TournamentStatus::RegistrationOpen);
    }

    /**
     * Close registration for the tournament.
     *
     * @throws InvalidStateTransitionException
     */
    public function closeRegistration(): void
    {
        $this->transitionTo(TournamentStatus::RegistrationClosed);
    }

    /**
     * Start the tournament.
     *
     * @throws InvalidStateTransitionException
     */
    public function start(): void
    {
        $this->transitionTo(TournamentStatus::InProgress);
        $this->startedAt = new DateTimeImmutable();
    }

    /**
     * Finish the tournament.
     *
     * @throws InvalidStateTransitionException
     */
    public function finish(): void
    {
        $this->transitionTo(TournamentStatus::Finished);
        $this->completedAt = new DateTimeImmutable();
    }

    /**
     * Cancel the tournament.
     *
     * @throws InvalidStateTransitionException
     */
    public function cancel(): void
    {
        $this->transitionTo(TournamentStatus::Cancelled);
    }

    /**
     * Check if registration is currently open.
     */
    public function isRegistrationOpen(): bool
    {
        return $this->status->isRegistrationOpen();
    }

    /**
     * Check if the tournament has a configured max rounds limit.
     */
    public function hasMaxRounds(): bool
    {
        return $this->maxRounds !== null;
    }

    /**
     * Increment the current round number.
     */
    public function incrementCurrentRound(): void
    {
        $this->currentRound++;
    }

    /**
     * Get the score points for a given result key.
     */
    public function getScoreForKey(string $key): float
    {
        foreach ($this->scoreWeights as $scoreWeight) {
            if ($scoreWeight->key === $key) {
                return $scoreWeight->points;
            }
        }

        return 0.0;
    }

    /**
     * Check if a user with the given roles can register.
     *
     * @param  array<string>  $userRoles
     */
    public function userHasAllowedRole(array $userRoles): bool
    {
        // Empty allowedRoles means all users are allowed
        if ($this->allowedRoles === []) {
            return true;
        }

        return count(array_intersect($userRoles, $this->allowedRoles)) > 0;
    }

    /**
     * Calculate the recommended number of rounds for a given number of participants.
     * Uses the formula: ceil(log2(n)) to ensure all participants can potentially meet.
     */
    public static function calculateRecommendedRounds(int $participants): int
    {
        if ($participants <= 1) {
            return 1;
        }

        return (int) ceil(log($participants, 2));
    }

    /**
     * Update the tournament name.
     */
    public function updateName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Update the tournament slug.
     */
    public function updateSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /**
     * Update the tournament description.
     */
    public function updateDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function gameProfileId(): ?string
    {
        return $this->gameProfileId;
    }

    /**
     * @return array<StatDefinition>|null
     */
    public function statDefinitions(): ?array
    {
        return $this->statDefinitions;
    }

    /**
     * @return array<ScoringRule>|null
     */
    public function scoringRules(): ?array
    {
        return $this->scoringRules;
    }

    /**
     * @return array<TiebreakerDefinition>|null
     */
    public function tiebreakerConfig(): ?array
    {
        return $this->tiebreakerConfig;
    }

    public function pairingConfig(): ?PairingConfig
    {
        return $this->pairingConfig;
    }

    public function showParticipants(): bool
    {
        return $this->showParticipants;
    }

    public function notificationEmail(): string
    {
        return $this->notificationEmail;
    }

    public function selfCheckInAllowed(): bool
    {
        return $this->selfCheckInAllowed;
    }

    /**
     * Get effective scoring rules, converting from scoreWeights if scoringRules is not set.
     *
     * @return array<ScoringRule>
     */
    public function getEffectiveScoringRules(): array
    {
        if ($this->scoringRules !== null) {
            return $this->scoringRules;
        }

        return array_map(
            fn (ScoreWeight $weight): ScoringRule => new ScoringRule(
                name: $weight->name,
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: $weight->key,
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: $weight->points,
                priority: 0,
            ),
            $this->scoreWeights
        );
    }

    /**
     * Get effective tiebreaker config, converting from tiebreakers if tiebreakerConfig is not set.
     *
     * @return array<TiebreakerDefinition>
     */
    public function getEffectiveTiebreakerConfig(): array
    {
        if ($this->tiebreakerConfig !== null) {
            return $this->tiebreakerConfig;
        }

        return array_map(
            fn (Tiebreaker $tiebreaker): TiebreakerDefinition => new TiebreakerDefinition(
                key: $tiebreaker->value,
                name: $tiebreaker->label(),
                type: TiebreakerType::from($this->mapTiebreakerToType($tiebreaker)),
            ),
            $this->tiebreakers
        );
    }

    /**
     * Get effective pairing config, returning a default if pairingConfig is not set.
     */
    public function getEffectivePairingConfig(): PairingConfig
    {
        return $this->pairingConfig ?? new PairingConfig();
    }

    /**
     * Map old Tiebreaker enum to new TiebreakerType.
     */
    private function mapTiebreakerToType(Tiebreaker $tiebreaker): string
    {
        return match ($tiebreaker) {
            Tiebreaker::Buchholz => TiebreakerType::Buchholz->value,
            Tiebreaker::MedianBuchholz => TiebreakerType::MedianBuchholz->value,
            Tiebreaker::Progressive => TiebreakerType::Progressive->value,
            Tiebreaker::HeadToHead => TiebreakerType::HeadToHead->value,
            Tiebreaker::OpponentWinPercentage => TiebreakerType::OpponentWinPercentage->value,
        };
    }

    /**
     * Transition to a new status with validation.
     *
     * @throws InvalidStateTransitionException
     */
    private function transitionTo(TournamentStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw InvalidStateTransitionException::tournament(
                $this->id->value,
                $this->status->value,
                $newStatus->value
            );
        }

        $this->status = $newStatus;
    }
}
