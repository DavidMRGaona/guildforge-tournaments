<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * A match result recorded from the panel; NotPlayed clears a recorded result.
 * The scores are the games (or points) each player won in the match.
 */
final readonly class RecordMatchResultDTO
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function __construct(
        public string $matchId,
        public MatchResult $result,
        public string $reportedById,
        public ?array $player1Stats = null,
        public ?array $player2Stats = null,
        public ?int $player1Score = null,
        public ?int $player2Score = null,
    ) {
    }
}
