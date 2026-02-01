<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;

final readonly class CreateTournamentDTO
{
    /**
     * @param  array<array{name: string, key: string, points: float}>  $scoreWeights
     * @param  array<Tiebreaker>  $tiebreakers
     * @param  array<string>  $allowedRoles
     */
    public function __construct(
        public string $eventId,
        public string $name,
        public ?string $description = null,
        public ?int $maxRounds = null,
        public ?int $maxParticipants = null,
        public ?int $minParticipants = null,
        public array $scoreWeights = [],
        public array $tiebreakers = [],
        public bool $allowGuests = false,
        public bool $requiresManualConfirmation = false,
        public array $allowedRoles = [],
        public ResultReporting $resultReporting = ResultReporting::AdminOnly,
        public bool $requiresCheckIn = false,
        public ?int $checkInStartsBefore = null,
        public ?DateTimeImmutable $registrationOpensAt = null,
        public ?DateTimeImmutable $registrationClosesAt = null,
        public string $notificationEmail = '',
        public bool $selfCheckInAllowed = false,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tiebreakers = [];
        if (isset($data['tiebreakers']) && is_array($data['tiebreakers'])) {
            foreach ($data['tiebreakers'] as $tiebreaker) {
                if ($tiebreaker instanceof Tiebreaker) {
                    $tiebreakers[] = $tiebreaker;
                } else {
                    $tiebreakers[] = Tiebreaker::from($tiebreaker);
                }
            }
        }

        $resultReporting = ResultReporting::AdminOnly;
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
            eventId: $data['event_id'],
            name: $data['name'],
            description: $data['description'] ?? null,
            maxRounds: $data['max_rounds'] ?? null,
            maxParticipants: $data['max_participants'] ?? null,
            minParticipants: $data['min_participants'] ?? null,
            scoreWeights: $data['score_weights'] ?? [],
            tiebreakers: $tiebreakers,
            allowGuests: $data['allow_guests'] ?? false,
            requiresManualConfirmation: $data['requires_manual_confirmation'] ?? false,
            allowedRoles: $data['allowed_roles'] ?? [],
            resultReporting: $resultReporting,
            requiresCheckIn: $data['requires_check_in'] ?? false,
            checkInStartsBefore: $data['check_in_starts_before'] ?? null,
            registrationOpensAt: $registrationOpensAt,
            registrationClosesAt: $registrationClosesAt,
            notificationEmail: $data['notification_email'] ?? '',
            selfCheckInAllowed: $data['self_check_in_allowed'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'name' => $this->name,
            'description' => $this->description,
            'max_rounds' => $this->maxRounds,
            'max_participants' => $this->maxParticipants,
            'min_participants' => $this->minParticipants,
            'score_weights' => $this->scoreWeights,
            'tiebreakers' => array_map(fn (Tiebreaker $t) => $t->value, $this->tiebreakers),
            'allow_guests' => $this->allowGuests,
            'requires_manual_confirmation' => $this->requiresManualConfirmation,
            'allowed_roles' => $this->allowedRoles,
            'result_reporting' => $this->resultReporting->value,
            'requires_check_in' => $this->requiresCheckIn,
            'check_in_starts_before' => $this->checkInStartsBefore,
            'registration_opens_at' => $this->registrationOpensAt?->format('c'),
            'registration_closes_at' => $this->registrationClosesAt?->format('c'),
            'notification_email' => $this->notificationEmail,
            'self_check_in_allowed' => $this->selfCheckInAllowed,
        ];
    }
}
