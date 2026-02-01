<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchHistoryModel;

final readonly class EloquentMatchHistoryRepository implements MatchHistoryRepositoryInterface
{
    public function save(MatchHistory $history): void
    {
        MatchHistoryModel::create([
            'id' => $history->id(),
            'match_id' => $history->matchId(),
            'previous_result' => $history->previousResult(),
            'new_result' => $history->newResult(),
            'previous_player_1_score' => $history->previousPlayer1Score(),
            'new_player_1_score' => $history->newPlayer1Score(),
            'previous_player_2_score' => $history->previousPlayer2Score(),
            'new_player_2_score' => $history->newPlayer2Score(),
            'changed_by_id' => $history->changedById(),
            'reason' => $history->reason(),
            'changed_at' => $history->changedAt(),
        ]);
    }

    /**
     * @return array<MatchHistory>
     */
    public function findByMatch(string $matchId): array
    {
        $models = MatchHistoryModel::where('match_id', $matchId)
            ->orderBy('changed_at')
            ->get();

        return $models->map(fn (MatchHistoryModel $model): MatchHistory => $this->toEntity($model))->all();
    }

    private function toEntity(MatchHistoryModel $model): MatchHistory
    {
        return new MatchHistory(
            id: $model->id,
            matchId: $model->match_id,
            previousResult: $model->previous_result,
            newResult: $model->new_result,
            previousPlayer1Score: $model->previous_player_1_score,
            newPlayer1Score: $model->new_player_1_score,
            previousPlayer2Score: $model->previous_player_2_score,
            newPlayer2Score: $model->new_player_2_score,
            changedById: $model->changed_by_id,
            reason: $model->reason,
            changedAt: $model->changed_at->toDateTimeImmutable(),
        );
    }
}
