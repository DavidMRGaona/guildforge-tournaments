<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;

final readonly class EloquentParticipantRepository implements ParticipantRepositoryInterface
{
    public function save(Participant $participant): void
    {
        ParticipantModel::updateOrCreate(
            ['id' => $participant->id()->value],
            [
                'tournament_id' => $participant->tournamentId(),
                'user_id' => $participant->userId(),
                'guest_name' => $participant->guestName(),
                'guest_email' => $participant->guestEmail(),
                'cancellation_token' => $participant->cancellationToken(),
                'status' => $participant->status(),
                'seed' => $participant->seed(),
                'has_received_bye' => $participant->hasReceivedBye(),
                'registered_at' => $participant->registeredAt(),
                'checked_in_at' => $participant->checkedInAt(),
            ]
        );
    }

    public function find(ParticipantId $id): ?Participant
    {
        $model = ParticipantModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(ParticipantId $id): Participant
    {
        $participant = $this->find($id);

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($id->value);
        }

        return $participant;
    }

    /**
     * @return array<Participant>
     */
    public function findByTournament(string $tournamentId): array
    {
        $models = ParticipantModel::where('tournament_id', $tournamentId)->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    /**
     * @return array<Participant>
     */
    public function findByTournamentAndStatus(string $tournamentId, ParticipantStatus $status): array
    {
        $models = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('status', $status)
            ->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    public function findByUserAndTournament(string $userId, string $tournamentId): ?Participant
    {
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('user_id', $userId)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByGuestEmailAndTournament(string $email, string $tournamentId): ?Participant
    {
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('guest_email', $email)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByEmailAndTournament(string $email, string $tournamentId): ?Participant
    {
        // First check guest_email
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('guest_email', $email)
            ->first();

        if ($model !== null) {
            return $this->toEntity($model);
        }

        // Then check user's email through the user relation
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->whereHas('user', function ($query) use ($email): void {
                $query->where('email', $email);
            })
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function countByTournament(string $tournamentId): int
    {
        return ParticipantModel::where('tournament_id', $tournamentId)->count();
    }

    public function countActiveByTournament(string $tournamentId): int
    {
        $activeStatuses = [
            ParticipantStatus::Registered,
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        return ParticipantModel::where('tournament_id', $tournamentId)
            ->whereIn('status', $activeStatuses)
            ->count();
    }

    /**
     * @return array<Participant>
     */
    public function findPlayableByTournament(string $tournamentId): array
    {
        $playableStatuses = [
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        $models = ParticipantModel::where('tournament_id', $tournamentId)
            ->whereIn('status', $playableStatuses)
            ->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    public function delete(ParticipantId $id): void
    {
        ParticipantModel::where('id', $id->value)->delete();
    }

    public function findByCancellationToken(string $token): ?Participant
    {
        $model = ParticipantModel::where('cancellation_token', $token)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    private function toEntity(ParticipantModel $model): Participant
    {
        return new Participant(
            id: ParticipantId::fromString($model->id),
            tournamentId: $model->tournament_id,
            status: $model->status,
            userId: $model->user_id,
            guestName: $model->guest_name,
            guestEmail: $model->guest_email,
            cancellationToken: $model->cancellation_token,
            seed: $model->seed,
            hasReceivedBye: $model->has_received_bye,
            registeredAt: $model->registered_at?->toDateTimeImmutable(),
            checkedInAt: $model->checked_in_at?->toDateTimeImmutable(),
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
