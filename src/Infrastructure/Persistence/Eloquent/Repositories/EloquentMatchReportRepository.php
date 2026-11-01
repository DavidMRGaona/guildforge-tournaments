<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchReportModel;

final readonly class EloquentMatchReportRepository implements MatchReportRepositoryInterface
{
    public function find(string $matchId): ?MatchReport
    {
        $model = MatchReportModel::query()->where('match_id', $matchId)->first();

        return $model instanceof MatchReportModel ? $this->toEntity($model) : null;
    }

    /**
     * @return array<string, MatchReport>
     */
    public function findByRound(string $roundId): array
    {
        $reports = [];
        $models = MatchReportModel::query()
            ->whereHas('match', static fn ($query) => $query->where('round_id', $roundId))
            ->get();

        foreach ($models as $model) {
            $reports[$model->match_id] = $this->toEntity($model);
        }

        return $reports;
    }

    public function save(MatchReport $report): void
    {
        MatchReportModel::query()->updateOrCreate(
            ['match_id' => $report->matchId()],
            [
                'result' => $report->result(),
                'player_1_score' => $report->player1Score(),
                'player_2_score' => $report->player2Score(),
                'player_1_stats' => $report->player1Stats(),
                'player_2_stats' => $report->player2Stats(),
                'reported_by_id' => $report->reportedById(),
                'reported_at' => $report->reportedAt(),
                'disputed_by_id' => $report->disputedById(),
                'dispute_reason' => $report->disputeReason(),
                'disputed_at' => $report->disputedAt(),
            ],
        );
    }

    public function delete(string $matchId): void
    {
        MatchReportModel::query()->where('match_id', $matchId)->delete();
    }

    private function toEntity(MatchReportModel $model): MatchReport
    {
        return new MatchReport(
            matchId: $model->match_id,
            result: $model->result,
            reportedById: $model->reported_by_id,
            reportedAt: $model->reported_at->toDateTimeImmutable(),
            player1Score: $model->player_1_score,
            player2Score: $model->player_2_score,
            player1Stats: $model->player_1_stats,
            player2Stats: $model->player_2_stats,
            disputedById: $model->disputed_by_id,
            disputeReason: $model->dispute_reason,
            disputedAt: $model->disputed_at?->toDateTimeImmutable(),
        );
    }
}
