<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use Modules\Tournaments\Domain\Enums\MatchResult;

final readonly class ReportMatchResultDTO
{
    public function __construct(
        public string $matchId,
        public MatchResult $result,
        public string $reportedById,
        public ?int $player1Score = null,
        public ?int $player2Score = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $result = $data['result'] instanceof MatchResult
            ? $data['result']
            : MatchResult::from($data['result']);

        return new self(
            matchId: $data['match_id'],
            result: $result,
            reportedById: $data['reported_by_id'],
            player1Score: $data['player_1_score'] ?? null,
            player2Score: $data['player_2_score'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'match_id' => $this->matchId,
            'result' => $this->result->value,
            'reported_by_id' => $this->reportedById,
            'player_1_score' => $this->player1Score,
            'player_2_score' => $this->player2Score,
        ];
    }
}
