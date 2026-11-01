<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * A result one of the match's players reports (or corrects) from the public site.
 */
final readonly class ReportMatchResultDTO
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function __construct(
        public string $matchId,
        public MatchResult $result,
        public string $reportedById,
        public ?int $player1Score = null,
        public ?int $player2Score = null,
        public ?array $player1Stats = null,
        public ?array $player2Stats = null,
    ) {}
}
