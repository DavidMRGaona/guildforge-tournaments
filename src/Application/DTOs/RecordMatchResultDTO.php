<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * A match result recorded from the panel; NotPlayed clears a recorded result.
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
    ) {
    }
}
