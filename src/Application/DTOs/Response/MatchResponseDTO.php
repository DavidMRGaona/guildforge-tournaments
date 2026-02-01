<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;

final readonly class MatchResponseDTO
{
    public function __construct(
        public string $id,
        public string $roundId,
        public string $player1Id,
        public string $player1Name,
        public ?string $player2Id,
        public ?string $player2Name,
        public ?int $tableNumber,
        public MatchResult $result,
        public ?int $player1Score,
        public ?int $player2Score,
        public ?string $reportedById,
        public ?string $reportedByName,
        public ?DateTimeImmutable $reportedAt,
        public ?string $confirmedById,
        public ?string $confirmedByName,
        public ?DateTimeImmutable $confirmedAt,
        public bool $isDisputed,
    ) {
    }

    public static function fromEntity(
        TournamentMatch $match,
        string $player1Name,
        ?string $player2Name = null,
        ?string $reportedByName = null,
        ?string $confirmedByName = null,
    ): self {
        return new self(
            id: $match->id()->value,
            roundId: $match->roundId(),
            player1Id: $match->player1Id(),
            player1Name: $player1Name,
            player2Id: $match->player2Id(),
            player2Name: $player2Name,
            tableNumber: $match->tableNumber(),
            result: $match->result(),
            player1Score: $match->player1Score(),
            player2Score: $match->player2Score(),
            reportedById: $match->reportedById(),
            reportedByName: $reportedByName,
            reportedAt: $match->reportedAt(),
            confirmedById: $match->confirmedById(),
            confirmedByName: $confirmedByName,
            confirmedAt: $match->confirmedAt(),
            isDisputed: $match->isDisputed(),
        );
    }

    public function isBye(): bool
    {
        return $this->result === MatchResult::Bye;
    }

    public function isReported(): bool
    {
        return $this->reportedAt !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }

    public function needsConfirmation(): bool
    {
        if ($this->isBye()) {
            return false;
        }

        return $this->isReported() && ! $this->isConfirmed();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'round_id' => $this->roundId,
            'player_1_id' => $this->player1Id,
            'player_1_name' => $this->player1Name,
            'player_2_id' => $this->player2Id,
            'player_2_name' => $this->player2Name,
            'table_number' => $this->tableNumber,
            'result' => $this->result->value,
            'result_label' => $this->result->label(),
            'player_1_score' => $this->player1Score,
            'player_2_score' => $this->player2Score,
            'reported_by_id' => $this->reportedById,
            'reported_by_name' => $this->reportedByName,
            'reported_at' => $this->reportedAt?->format('c'),
            'confirmed_by_id' => $this->confirmedById,
            'confirmed_by_name' => $this->confirmedByName,
            'confirmed_at' => $this->confirmedAt?->format('c'),
            'is_disputed' => $this->isDisputed,
            'is_bye' => $this->isBye(),
            'is_reported' => $this->isReported(),
            'is_confirmed' => $this->isConfirmed(),
            'needs_confirmation' => $this->needsConfirmation(),
        ];
    }
}
