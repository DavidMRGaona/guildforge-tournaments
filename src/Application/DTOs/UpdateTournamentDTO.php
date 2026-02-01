<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;

final readonly class UpdateTournamentDTO
{
    /**
     * @param  array<array{name: string, key: string, points: float}>|null  $scoreWeights
     * @param  array<Tiebreaker>|null  $tiebreakers
     * @param  array<string>|null  $allowedRoles
     */
    public function __construct(
        public string $tournamentId,
        public ?string $name = null,
        public ?string $description = null,
        public ?int $maxRounds = null,
        public ?int $maxParticipants = null,
        public ?int $minParticipants = null,
        public ?array $scoreWeights = null,
        public ?array $tiebreakers = null,
        public ?bool $allowGuests = null,
        public ?bool $requiresManualConfirmation = null,
        public ?array $allowedRoles = null,
        public ?ResultReporting $resultReporting = null,
        public ?bool $requiresCheckIn = null,
        public ?int $checkInStartsBefore = null,
        public ?DateTimeImmutable $registrationOpensAt = null,
        public ?DateTimeImmutable $registrationClosesAt = null,
        public ?string $notificationEmail = null,
        public ?bool $selfCheckInAllowed = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tiebreakers = null;
        if (isset($data['tiebreakers']) && is_array($data['tiebreakers'])) {
            $tiebreakers = [];
            foreach ($data['tiebreakers'] as $tiebreaker) {
                if ($tiebreaker instanceof Tiebreaker) {
                    $tiebreakers[] = $tiebreaker;
                } else {
                    $tiebreakers[] = Tiebreaker::from($tiebreaker);
                }
            }
        }

        $resultReporting = null;
        if (isset($data['result_reporting'])) {
            if ($data['result_reporting'] instanceof ResultReporting) {
                $resultReporting = $data['result_reporting'];
            } else {
                $resultReporting = ResultReporting::from($data['result_reporting']);
            }
        }

        $registrationOpensAt = null;
        if (isset($data['registration_opens_at'])) {
            $registrationOpensAt = $data['registration_opens_at'] instanceof DateTimeImmutable
                ? $data['registration_opens_at']
                : new DateTimeImmutable($data['registration_opens_at']);
        }

        $registrationClosesAt = null;
        if (isset($data['registration_closes_at'])) {
            $registrationClosesAt = $data['registration_closes_at'] instanceof DateTimeImmutable
                ? $data['registration_closes_at']
                : new DateTimeImmutable($data['registration_closes_at']);
        }

        return new self(
            tournamentId: $data['tournament_id'],
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            maxRounds: $data['max_rounds'] ?? null,
            maxParticipants: $data['max_participants'] ?? null,
            minParticipants: $data['min_participants'] ?? null,
            scoreWeights: $data['score_weights'] ?? null,
            tiebreakers: $tiebreakers,
            allowGuests: $data['allow_guests'] ?? null,
            requiresManualConfirmation: $data['requires_manual_confirmation'] ?? null,
            allowedRoles: $data['allowed_roles'] ?? null,
            resultReporting: $resultReporting,
            requiresCheckIn: $data['requires_check_in'] ?? null,
            checkInStartsBefore: $data['check_in_starts_before'] ?? null,
            registrationOpensAt: $registrationOpensAt,
            registrationClosesAt: $registrationClosesAt,
            notificationEmail: $data['notification_email'] ?? null,
            selfCheckInAllowed: $data['self_check_in_allowed'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['tournament_id' => $this->tournamentId];

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }
        if ($this->description !== null) {
            $result['description'] = $this->description;
        }
        if ($this->maxRounds !== null) {
            $result['max_rounds'] = $this->maxRounds;
        }
        if ($this->maxParticipants !== null) {
            $result['max_participants'] = $this->maxParticipants;
        }
        if ($this->minParticipants !== null) {
            $result['min_participants'] = $this->minParticipants;
        }
        if ($this->scoreWeights !== null) {
            $result['score_weights'] = $this->scoreWeights;
        }
        if ($this->tiebreakers !== null) {
            $result['tiebreakers'] = array_map(fn (Tiebreaker $t) => $t->value, $this->tiebreakers);
        }
        if ($this->allowGuests !== null) {
            $result['allow_guests'] = $this->allowGuests;
        }
        if ($this->requiresManualConfirmation !== null) {
            $result['requires_manual_confirmation'] = $this->requiresManualConfirmation;
        }
        if ($this->allowedRoles !== null) {
            $result['allowed_roles'] = $this->allowedRoles;
        }
        if ($this->resultReporting !== null) {
            $result['result_reporting'] = $this->resultReporting->value;
        }
        if ($this->requiresCheckIn !== null) {
            $result['requires_check_in'] = $this->requiresCheckIn;
        }
        if ($this->checkInStartsBefore !== null) {
            $result['check_in_starts_before'] = $this->checkInStartsBefore;
        }
        if ($this->registrationOpensAt !== null) {
            $result['registration_opens_at'] = $this->registrationOpensAt->format('c');
        }
        if ($this->registrationClosesAt !== null) {
            $result['registration_closes_at'] = $this->registrationClosesAt->format('c');
        }
        if ($this->notificationEmail !== null) {
            $result['notification_email'] = $this->notificationEmail;
        }
        if ($this->selfCheckInAllowed !== null) {
            $result['self_check_in_allowed'] = $this->selfCheckInAllowed;
        }

        return $result;
    }
}
