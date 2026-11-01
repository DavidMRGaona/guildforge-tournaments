<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * An official match result: recorded from the panel, reported by a player the tournament
 * trusts, or a player's report once confirmed. NotPlayed clears a recorded result.
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
        public MatchHistoryAction $action = MatchHistoryAction::Recorded,
        public ?string $confirmedById = null,
        // Who made the change, for the history: the reporter when null
        public ?string $changedById = null,
        public ?string $reason = null,
    ) {}
}
