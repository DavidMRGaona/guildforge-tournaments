<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\Services\ScoringRuleEvaluatorInterface;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;

final class ScoringRuleEvaluator implements ScoringRuleEvaluatorInterface
{
    /**
     * @param  array<ScoringRule>  $scoringRules
     * @param  array<string, mixed>  $player1Stats
     * @param  array<string, mixed>  $player2Stats
     * @return array{player1: float, player2: float}
     */
    public function evaluate(
        MatchResult $result,
        array $scoringRules,
        array $player1Stats = [],
        array $player2Stats = [],
    ): array {
        if ($scoringRules === []) {
            return ['player1' => 0.0, 'player2' => 0.0];
        }

        $sortedRules = $this->sortRulesByPriority($scoringRules);

        $player1Result = $this->getPlayerResult($result, true);
        $player2Result = $this->getPlayerResult($result, false);

        $player1Points = $this->evaluateRulesForPlayer(
            $sortedRules,
            $player1Result,
            $player1Stats,
            $player2Stats,
        );

        $player2Points = $this->evaluateRulesForPlayer(
            $sortedRules,
            $player2Result,
            $player2Stats,
            $player1Stats,
        );

        return ['player1' => $player1Points, 'player2' => $player2Points];
    }

    /**
     * Sort scoring rules by priority (highest first).
     *
     * @param  array<ScoringRule>  $rules
     * @return array<ScoringRule>
     */
    private function sortRulesByPriority(array $rules): array
    {
        usort($rules, static fn (ScoringRule $a, ScoringRule $b): int => $b->priority <=> $a->priority);

        return $rules;
    }

    /**
     * Get the result from a specific player's perspective.
     */
    private function getPlayerResult(MatchResult $result, bool $isPlayerOne): string
    {
        return match ($result) {
            MatchResult::PlayerOneWin => $isPlayerOne ? 'win' : 'loss',
            MatchResult::PlayerTwoWin => $isPlayerOne ? 'loss' : 'win',
            MatchResult::Draw => 'draw',
            MatchResult::DoubleLoss => 'loss',
            MatchResult::Bye => $isPlayerOne ? 'bye' : 'loss',
            MatchResult::NotPlayed => 'not_played',
        };
    }

    /**
     * Evaluate rules for a single player and return the first matching rule's points.
     *
     * @param  array<ScoringRule>  $rules
     * @param  array<string, mixed>  $playerStats
     * @param  array<string, mixed>  $opponentStats
     */
    private function evaluateRulesForPlayer(
        array $rules,
        string $playerResult,
        array $playerStats,
        array $opponentStats,
    ): float {
        foreach ($rules as $rule) {
            if ($this->conditionMatches($rule->condition, $playerResult, $playerStats, $opponentStats)) {
                return $rule->points;
            }
        }

        return 0.0;
    }

    /**
     * Check if a condition matches for the current player.
     *
     * @param  array<string, mixed>  $playerStats
     * @param  array<string, mixed>  $opponentStats
     */
    private function conditionMatches(
        ScoringCondition $condition,
        string $playerResult,
        array $playerStats,
        array $opponentStats,
    ): bool {
        return match ($condition->type) {
            ConditionType::Result => $this->matchesResultCondition($condition, $playerResult),
            ConditionType::StatComparison => $this->matchesStatComparisonCondition($condition, $playerStats, $opponentStats),
            ConditionType::StatThreshold => $this->matchesStatThresholdCondition($condition, $playerStats),
            ConditionType::MarginDifference => $this->matchesMarginDifferenceCondition($condition, $playerStats, $opponentStats),
        };
    }

    /**
     * Check if a Result condition matches.
     */
    private function matchesResultCondition(ScoringCondition $condition, string $playerResult): bool
    {
        return $condition->resultValue === $playerResult;
    }

    /**
     * Check if a StatComparison condition matches (player stat vs opponent stat).
     *
     * @param  array<string, mixed>  $playerStats
     * @param  array<string, mixed>  $opponentStats
     */
    private function matchesStatComparisonCondition(
        ScoringCondition $condition,
        array $playerStats,
        array $opponentStats,
    ): bool {
        $stat = $condition->stat;
        $operator = $condition->operator;

        if ($stat === null || $operator === null) {
            return false;
        }

        $playerValue = (float) ($playerStats[$stat] ?? 0);
        $opponentValue = (float) ($opponentStats[$stat] ?? 0);

        return $this->compareValues($playerValue, $operator, $opponentValue);
    }

    /**
     * Check if a StatThreshold condition matches (player stat vs threshold value).
     *
     * @param  array<string, mixed>  $playerStats
     */
    private function matchesStatThresholdCondition(ScoringCondition $condition, array $playerStats): bool
    {
        $stat = $condition->stat;
        $operator = $condition->operator;
        $thresholdValue = $condition->value;

        if ($stat === null || $operator === null || $thresholdValue === null) {
            return false;
        }

        $playerValue = (float) ($playerStats[$stat] ?? 0);

        return $this->compareValues($playerValue, $operator, $thresholdValue);
    }

    /**
     * Check if a MarginDifference condition matches (player stat - opponent stat vs threshold).
     *
     * For operators `>` and `>=`, the player must be ahead (positive margin) to match.
     * For operators `<` and `<=`, the player must be behind (negative margin) to match.
     * This provides semantic correctness for rules like "crushing victory" (>= X) or "close loss" (< X).
     *
     * @param  array<string, mixed>  $playerStats
     * @param  array<string, mixed>  $opponentStats
     */
    private function matchesMarginDifferenceCondition(
        ScoringCondition $condition,
        array $playerStats,
        array $opponentStats,
    ): bool {
        $stat = $condition->stat;
        $operator = $condition->operator;
        $thresholdValue = $condition->value;

        if ($stat === null || $operator === null || $thresholdValue === null) {
            return false;
        }

        $playerValue = (float) ($playerStats[$stat] ?? 0);
        $opponentValue = (float) ($opponentStats[$stat] ?? 0);
        $margin = $playerValue - $opponentValue;

        // For "close loss" scenarios (< or <=), player must be behind
        if (($operator === '<' || $operator === '<=') && $margin >= 0) {
            return false;
        }

        // For "crushing victory" scenarios (> or >=), player must be ahead
        if (($operator === '>' || $operator === '>=') && $margin <= 0) {
            return false;
        }

        // Compare the absolute margin against the threshold for < and <= operators
        $valueToCompare = ($operator === '<' || $operator === '<=') ? abs($margin) : $margin;

        return $this->compareValues($valueToCompare, $operator, $thresholdValue);
    }

    /**
     * Compare two values using the specified operator.
     */
    private function compareValues(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '==' => $left === $right,
            default => false,
        };
    }
}
