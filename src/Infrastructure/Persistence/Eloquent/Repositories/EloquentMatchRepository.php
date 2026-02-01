<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;

final readonly class EloquentMatchRepository implements MatchRepositoryInterface
{
    public function save(TournamentMatch $match): void
    {
        MatchModel::updateOrCreate(
            ['id' => $match->id()->value],
            [
                'round_id' => $match->roundId(),
                'player_1_id' => $match->player1Id(),
                'player_2_id' => $match->player2Id(),
                'table_number' => $match->tableNumber(),
                'result' => $match->result(),
                'player_1_score' => $match->player1Score(),
                'player_2_score' => $match->player2Score(),
                'reported_by_id' => $match->reportedById(),
                'reported_at' => $match->reportedAt(),
                'confirmed_by_id' => $match->confirmedById(),
                'confirmed_at' => $match->confirmedAt(),
                'is_disputed' => $match->isDisputed(),
            ]
        );
    }

    /**
     * @param  array<TournamentMatch>  $matches
     */
    public function saveMany(array $matches): void
    {
        foreach ($matches as $match) {
            $this->save($match);
        }
    }

    public function find(MatchId $id): ?TournamentMatch
    {
        $model = MatchModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(MatchId $id): TournamentMatch
    {
        $match = $this->find($id);

        if ($match === null) {
            throw MatchNotFoundException::withId($id->value);
        }

        return $match;
    }

    /**
     * @return array<TournamentMatch>
     */
    public function findByRound(string $roundId): array
    {
        $models = MatchModel::where('round_id', $roundId)->get();

        return $models->map(fn (MatchModel $model): TournamentMatch => $this->toEntity($model))->all();
    }

    /**
     * @return array<TournamentMatch>
     */
    public function findByParticipant(string $participantId): array
    {
        $models = MatchModel::where('player_1_id', $participantId)
            ->orWhere('player_2_id', $participantId)
            ->get();

        return $models->map(fn (MatchModel $model): TournamentMatch => $this->toEntity($model))->all();
    }

    /**
     * @return array<TournamentMatch>
     */
    public function findByParticipantAndTournament(string $participantId, string $tournamentId): array
    {
        $roundIds = RoundModel::where('tournament_id', $tournamentId)->pluck('id');

        $models = MatchModel::whereIn('round_id', $roundIds)
            ->where(function ($query) use ($participantId): void {
                $query->where('player_1_id', $participantId)
                    ->orWhere('player_2_id', $participantId);
            })
            ->get();

        return $models->map(fn (MatchModel $model): TournamentMatch => $this->toEntity($model))->all();
    }

    public function havePlayedBefore(string $participant1Id, string $participant2Id, string $tournamentId): bool
    {
        $roundIds = RoundModel::where('tournament_id', $tournamentId)->pluck('id');

        return MatchModel::whereIn('round_id', $roundIds)
            ->where(function ($query) use ($participant1Id, $participant2Id): void {
                $query->where(function ($q) use ($participant1Id, $participant2Id): void {
                    $q->where('player_1_id', $participant1Id)
                        ->where('player_2_id', $participant2Id);
                })->orWhere(function ($q) use ($participant1Id, $participant2Id): void {
                    $q->where('player_1_id', $participant2Id)
                        ->where('player_2_id', $participant1Id);
                });
            })
            ->exists();
    }

    public function countUnreportedByRound(string $roundId): int
    {
        return MatchModel::where('round_id', $roundId)
            ->where('result', MatchResult::NotPlayed)
            ->count();
    }

    public function findByParticipantAndRound(string $participantId, string $roundId): ?TournamentMatch
    {
        $model = MatchModel::where('round_id', $roundId)
            ->where(function ($query) use ($participantId): void {
                $query->where('player_1_id', $participantId)
                    ->orWhere('player_2_id', $participantId);
            })
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function delete(MatchId $id): void
    {
        MatchModel::where('id', $id->value)->delete();
    }

    private function toEntity(MatchModel $model): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::fromString($model->id),
            roundId: $model->round_id,
            player1Id: $model->player_1_id,
            player2Id: $model->player_2_id,
            result: $model->result,
            tableNumber: $model->table_number,
            player1Score: $model->player_1_score,
            player2Score: $model->player_2_score,
            reportedById: $model->reported_by_id,
            reportedAt: $model->reported_at?->toDateTimeImmutable(),
            confirmedById: $model->confirmed_by_id,
            confirmedAt: $model->confirmed_at?->toDateTimeImmutable(),
            isDisputed: $model->is_disputed,
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
