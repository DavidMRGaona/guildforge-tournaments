<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;

interface ScoringRuleEvaluatorInterface
{
    /**
     * Evaluate scoring rules for a match and return points for each player.
     *
     * @param  array<ScoringRule>  $scoringRules  Scoring rules to evaluate
     * @param  array<string, mixed>  $player1Stats  Stats for player 1
     * @param  array<string, mixed>  $player2Stats  Stats for player 2
     * @return array{player1: float, player2: float}  Points awarded to each player
     */
    public function evaluate(
        MatchResult $result,
        array $scoringRules,
        array $player1Stats = [],
        array $player2Stats = [],
    ): array;
}
