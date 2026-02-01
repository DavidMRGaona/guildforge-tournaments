<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class EloquentTournamentRepository implements TournamentRepositoryInterface
{
    public function save(Tournament $tournament): void
    {
        TournamentModel::updateOrCreate(
            ['id' => $tournament->id()->value],
            [
                'event_id' => $tournament->eventId(),
                'name' => $tournament->name(),
                'slug' => $tournament->slug(),
                'description' => $tournament->description(),
                'image_public_id' => $tournament->imagePublicId(),
                'status' => $tournament->status(),
                'max_rounds' => $tournament->maxRounds(),
                'current_round' => $tournament->currentRound(),
                'max_participants' => $tournament->maxParticipants(),
                'min_participants' => $tournament->minParticipants(),
                'allow_guests' => $tournament->allowGuests(),
                'requires_manual_confirmation' => $tournament->requiresManualConfirmation(),
                'allowed_roles' => $tournament->allowedRoles() ?: null,
                'requires_check_in' => $tournament->requiresCheckIn(),
                'check_in_starts_before' => $tournament->checkInStartsBefore(),
                'result_reporting' => $tournament->resultReporting(),
                'game_profile_id' => $tournament->gameProfileId(),
                'stat_definitions' => $tournament->statDefinitions() !== null
                    ? array_map(
                        static fn (StatDefinition $sd): array => $sd->toArray(),
                        $tournament->statDefinitions()
                    )
                    : null,
                'scoring_rules' => $tournament->scoringRules() !== null
                    ? array_map(
                        static fn (ScoringRule $sr): array => $sr->toArray(),
                        $tournament->scoringRules()
                    )
                    : null,
                'tiebreaker_config' => $tournament->tiebreakerConfig() !== null
                    ? array_map(
                        static fn (TiebreakerDefinition $td): array => $td->toArray(),
                        $tournament->tiebreakerConfig()
                    )
                    : null,
                'pairing_config' => $tournament->pairingConfig()?->toArray(),
                'show_participants' => $tournament->showParticipants(),
                'notification_email' => $tournament->notificationEmail(),
                'self_check_in_allowed' => $tournament->selfCheckInAllowed(),
                'registration_opens_at' => $tournament->registrationOpensAt(),
                'registration_closes_at' => $tournament->registrationClosesAt(),
                'started_at' => $tournament->startedAt(),
                'completed_at' => $tournament->completedAt(),
            ]
        );
    }

    public function find(TournamentId $id): ?Tournament
    {
        $model = TournamentModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(TournamentId $id): Tournament
    {
        $tournament = $this->find($id);

        if ($tournament === null) {
            throw TournamentNotFoundException::withId($id->value);
        }

        return $tournament;
    }

    public function findBySlug(string $slug): ?Tournament
    {
        $model = TournamentModel::where('slug', $slug)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByEventId(string $eventId): ?Tournament
    {
        $model = TournamentModel::where('event_id', $eventId)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    /**
     * @return array<Tournament>
     */
    public function findByStatus(TournamentStatus $status): array
    {
        $models = TournamentModel::where('status', $status)->get();

        return $models->map(fn (TournamentModel $model): Tournament => $this->toEntity($model))->all();
    }

    public function delete(TournamentId $id): void
    {
        TournamentModel::where('id', $id->value)->delete();
    }

    /**
     * @return array<Tournament>
     */
    public function all(): array
    {
        $models = TournamentModel::all();

        return $models->map(fn (TournamentModel $model): Tournament => $this->toEntity($model))->all();
    }

    private function toEntity(TournamentModel $model): Tournament
    {
        $statDefinitions = null;
        if ($model->stat_definitions !== null) {
            $statDefinitions = array_map(
                static fn (array $data): StatDefinition => StatDefinition::fromArray($data),
                $model->stat_definitions
            );
        }

        $scoringRules = null;
        if ($model->scoring_rules !== null) {
            $scoringRules = array_map(
                static fn (array $data): ScoringRule => ScoringRule::fromArray($data),
                $model->scoring_rules
            );
        }

        $tiebreakerConfig = null;
        if ($model->tiebreaker_config !== null) {
            $tiebreakerConfig = array_map(
                static fn (array $data): TiebreakerDefinition => TiebreakerDefinition::fromArray($data),
                $model->tiebreaker_config
            );
        }

        $pairingConfig = null;
        if ($model->pairing_config !== null) {
            $pairingConfig = PairingConfig::fromArray($model->pairing_config);
        }

        return new Tournament(
            id: TournamentId::fromString($model->id),
            eventId: $model->event_id,
            name: $model->name,
            slug: $model->slug,
            status: $model->status,
            scoreWeights: [],
            tiebreakers: [],
            resultReporting: $model->result_reporting,
            description: $model->description,
            imagePublicId: $model->image_public_id,
            maxRounds: $model->max_rounds,
            currentRound: $model->current_round,
            maxParticipants: $model->max_participants,
            minParticipants: $model->min_participants,
            registrationOpensAt: $model->registration_opens_at?->toDateTimeImmutable(),
            registrationClosesAt: $model->registration_closes_at?->toDateTimeImmutable(),
            requiresCheckIn: $model->requires_check_in,
            checkInStartsBefore: $model->check_in_starts_before,
            allowGuests: $model->allow_guests,
            requiresManualConfirmation: $model->requires_manual_confirmation ?? false,
            allowedRoles: $model->allowed_roles ?? [],
            startedAt: $model->started_at?->toDateTimeImmutable(),
            completedAt: $model->completed_at?->toDateTimeImmutable(),
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
            gameProfileId: $model->game_profile_id,
            statDefinitions: $statDefinitions,
            scoringRules: $scoringRules,
            tiebreakerConfig: $tiebreakerConfig,
            pairingConfig: $pairingConfig,
            showParticipants: $model->show_participants ?? true,
            notificationEmail: $model->notification_email ?? '',
            selfCheckInAllowed: $model->self_check_in_allowed ?? false,
        );
    }
}
