<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;

final readonly class EloquentStandingRepository implements StandingRepositoryInterface
{
    public function save(Standing $standing): void
    {
        StandingModel::updateOrCreate(
            ['id' => $standing->id()],
            [
                'tournament_id' => $standing->tournamentId(),
                'participant_id' => $standing->participantId(),
                'rank' => $standing->rank(),
                'matches_played' => $standing->matchesPlayed(),
                'wins' => $standing->wins(),
                'draws' => $standing->draws(),
                'losses' => $standing->losses(),
                'byes' => $standing->byes(),
                'points' => $standing->points(),
                'buchholz' => $standing->buchholz(),
                'median_buchholz' => $standing->medianBuchholz(),
                'progressive' => $standing->progressive(),
                'opponent_win_percentage' => $standing->opponentWinPercentage(),
            ]
        );
    }

    /**
     * @param  array<Standing>  $standings
     */
    public function saveMany(array $standings): void
    {
        foreach ($standings as $standing) {
            $this->save($standing);
        }
    }

    /**
     * @return array<Standing>
     */
    public function findByTournament(string $tournamentId): array
    {
        $models = StandingModel::where('tournament_id', $tournamentId)->get();

        return $models->map(fn (StandingModel $model): Standing => $this->toEntity($model))->all();
    }

    /**
     * @return array<Standing>
     */
    public function findByTournamentOrderedByRank(string $tournamentId): array
    {
        $models = StandingModel::where('tournament_id', $tournamentId)
            ->orderBy('rank')
            ->get();

        return $models->map(fn (StandingModel $model): Standing => $this->toEntity($model))->all();
    }

    public function findByParticipant(string $participantId): ?Standing
    {
        $model = StandingModel::where('participant_id', $participantId)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByParticipantAndTournament(string $participantId, string $tournamentId): ?Standing
    {
        $model = StandingModel::where('tournament_id', $tournamentId)
            ->where('participant_id', $participantId)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function deleteByTournament(string $tournamentId): void
    {
        StandingModel::where('tournament_id', $tournamentId)->delete();
    }

    private function toEntity(StandingModel $model): Standing
    {
        return new Standing(
            id: $model->id,
            tournamentId: $model->tournament_id,
            participantId: $model->participant_id,
            rank: $model->rank,
            matchesPlayed: $model->matches_played,
            wins: $model->wins,
            draws: $model->draws,
            losses: $model->losses,
            byes: $model->byes,
            points: $model->points,
            buchholz: $model->buchholz,
            medianBuchholz: $model->median_buchholz,
            progressive: $model->progressive,
            opponentWinPercentage: $model->opponent_win_percentage,
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
