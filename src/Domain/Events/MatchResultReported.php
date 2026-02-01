<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Events;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Enums\MatchResult;

final readonly class MatchResultReported
{
    public function __construct(
        public string $matchId,
        public string $roundId,
        public string $tournamentId,
        public string $player1Id,
        public ?string $player2Id,
        public MatchResult $result,
        public ?int $player1Score,
        public ?int $player2Score,
        public string $reportedById,
        public bool $requiresConfirmation,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $matchId,
        string $roundId,
        string $tournamentId,
        string $player1Id,
        ?string $player2Id,
        MatchResult $result,
        ?int $player1Score,
        ?int $player2Score,
        string $reportedById,
        bool $requiresConfirmation,
    ): self {
        return new self(
            matchId: $matchId,
            roundId: $roundId,
            tournamentId: $tournamentId,
            player1Id: $player1Id,
            player2Id: $player2Id,
            result: $result,
            player1Score: $player1Score,
            player2Score: $player2Score,
            reportedById: $reportedById,
            requiresConfirmation: $requiresConfirmation,
            occurredAt: new DateTimeImmutable(),
        );
    }
}
