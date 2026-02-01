<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Exceptions\RoundNotFoundException;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;

final readonly class EloquentRoundRepository implements RoundRepositoryInterface
{
    public function save(Round $round): void
    {
        RoundModel::updateOrCreate(
            ['id' => $round->id()->value],
            [
                'tournament_id' => $round->tournamentId(),
                'round_number' => $round->roundNumber(),
                'status' => $round->status(),
                'started_at' => $round->startedAt(),
                'completed_at' => $round->completedAt(),
            ]
        );
    }

    public function find(RoundId $id): ?Round
    {
        $model = RoundModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(RoundId $id): Round
    {
        $round = $this->find($id);

        if ($round === null) {
            throw RoundNotFoundException::withId($id->value);
        }

        return $round;
    }

    /**
     * @return array<Round>
     */
    public function findByTournament(string $tournamentId): array
    {
        $models = RoundModel::where('tournament_id', $tournamentId)
            ->orderBy('round_number')
            ->get();

        return $models->map(fn (RoundModel $model): Round => $this->toEntity($model))->all();
    }

    public function findCurrentRound(string $tournamentId): ?Round
    {
        // First check for an in_progress round
        $model = RoundModel::where('tournament_id', $tournamentId)
            ->where('status', RoundStatus::InProgress)
            ->orderBy('round_number', 'desc')
            ->first();

        if ($model !== null) {
            return $this->toEntity($model);
        }

        // Then check for a pending round
        $model = RoundModel::where('tournament_id', $tournamentId)
            ->where('status', RoundStatus::Pending)
            ->orderBy('round_number')
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByTournamentAndNumber(string $tournamentId, int $roundNumber): ?Round
    {
        $model = RoundModel::where('tournament_id', $tournamentId)
            ->where('round_number', $roundNumber)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findLatestCompletedRound(string $tournamentId): ?Round
    {
        $model = RoundModel::where('tournament_id', $tournamentId)
            ->where('status', RoundStatus::Finished)
            ->orderBy('round_number', 'desc')
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function delete(RoundId $id): void
    {
        RoundModel::where('id', $id->value)->delete();
    }

    private function toEntity(RoundModel $model): Round
    {
        return new Round(
            id: RoundId::fromString($model->id),
            tournamentId: $model->tournament_id,
            roundNumber: $model->round_number,
            status: $model->status,
            startedAt: $model->started_at?->toDateTimeImmutable(),
            completedAt: $model->completed_at?->toDateTimeImmutable(),
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
