<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Application\Services\ScoringRuleEvaluatorInterface;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Infrastructure\Services\ScoringRuleEvaluator;
use PHPUnit\Framework\TestCase;

final class ScoringRuleEvaluatorTest extends TestCase
{
    private ScoringRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ScoringRuleEvaluator();
    }

    public function test_it_implements_scoring_rule_evaluator_interface(): void
    {
        $this->assertInstanceOf(ScoringRuleEvaluatorInterface::class, $this->evaluator);
    }

    public function test_it_evaluates_simple_win_rule(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(3.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_draw_rule(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Empate',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 1.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::Draw,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(1.0, $result['player1']);
        $this->assertEquals(1.0, $result['player2']);
    }

    public function test_it_evaluates_loss_rule(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Derrota',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'loss',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerTwoWin,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(0.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_bye_rule(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Bye',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'bye',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::Bye,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(3.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_margin_based_rule_when_condition_met(): void
    {
        // Blood Bowl example: if TD margin >= 3, bonus points
        $rules = [
            new ScoringRule(
                name: 'Victoria aplastante',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'touchdowns',
                    operator: '>=',
                    value: 3.0,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria normal',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['touchdowns' => 5],
            player2Stats: ['touchdowns' => 2],
        );

        // Player 1 wins with margin >= 3, gets bonus points (priority 10 rule)
        $this->assertEquals(1.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_margin_based_rule_when_condition_not_met(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Victoria aplastante',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'touchdowns',
                    operator: '>=',
                    value: 3.0,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria normal',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['touchdowns' => 3],
            player2Stats: ['touchdowns' => 2],
        );

        // Margin is only 1, falls through to normal win rule
        $this->assertEquals(3.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_threshold_rule_when_condition_met(): void
    {
        // Warhammer example: if VP >= 45, bonus points
        $rules = [
            new ScoringRule(
                name: 'Victoria por puntos altos',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 45.0,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria normal',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 50],
            player2Stats: ['victory_points' => 30],
        );

        // Player 1 has VP >= 45, gets bonus points
        $this->assertEquals(1.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_threshold_rule_when_condition_not_met(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Victoria por puntos altos',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 45.0,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria normal',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 40],
            player2Stats: ['victory_points' => 30],
        );

        // Player 1 has VP < 45, falls through to normal win rule
        $this->assertEquals(3.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_rules_by_priority_order(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Victoria baja prioridad',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
            new ScoringRule(
                name: 'Victoria alta prioridad',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 5.0,
                priority: 20,
            ),
            new ScoringRule(
                name: 'Victoria media prioridad',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 4.0,
                priority: 10,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        // Should use highest priority rule (20) which gives 5 points
        $this->assertEquals(5.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_stops_evaluation_at_first_matching_rule(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Primera regla',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 5.0,
                priority: 20,
            ),
            new ScoringRule(
                name: 'Segunda regla (no debería evaluarse)',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 10.0,
                priority: 10,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        // Should stop at first matching rule (priority 20)
        $this->assertEquals(5.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_returns_zero_points_with_empty_rules(): void
    {
        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: [],
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(0.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_both_players_simultaneously_for_draw(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Empate',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 1.5,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::Draw,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(1.5, $result['player1']);
        $this->assertEquals(1.5, $result['player2']);
    }

    public function test_it_evaluates_both_players_with_stat_comparison(): void
    {
        // Both players can earn points based on their own stats
        $rules = [
            new ScoringRule(
                name: 'Bonus por VP alto',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 40.0,
                ),
                points: 2.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 45],
            player2Stats: ['victory_points' => 42],
        );

        // Both players meet the threshold, each gets 2 points
        $this->assertEquals(2.0, $result['player1']);
        $this->assertEquals(2.0, $result['player2']);
    }

    public function test_it_evaluates_player_two_win(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
            new ScoringRule(
                name: 'Derrota',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'loss',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerTwoWin,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(0.0, $result['player1']);
        $this->assertEquals(3.0, $result['player2']);
    }

    public function test_it_evaluates_double_loss(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Derrota',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'loss',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::DoubleLoss,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(0.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_handles_fractional_points(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Empate',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.5,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::Draw,
            scoringRules: $rules,
            player1Stats: [],
            player2Stats: [],
        );

        $this->assertEquals(0.5, $result['player1']);
        $this->assertEquals(0.5, $result['player2']);
    }

    public function test_it_evaluates_complex_multi_rule_scenario(): void
    {
        // Complex scenario: multiple rules with different priorities and conditions
        $rules = [
            new ScoringRule(
                name: 'Victoria perfecta',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 100.0,
                ),
                points: 5.0,
                priority: 30,
            ),
            new ScoringRule(
                name: 'Victoria aplastante',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 50.0,
                ),
                points: 4.0,
                priority: 20,
            ),
            new ScoringRule(
                name: 'Victoria normal',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        // Player 1 wins with 95 VP vs Player 2 with 40 VP
        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 95],
            player2Stats: ['victory_points' => 40],
        );

        // Should match "Victoria aplastante" (margin >= 50), not perfect (< 100)
        $this->assertEquals(4.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_comparison_with_less_than_operator(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Bonus derrota digna',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '<',
                    value: 10.0,
                ),
                points: 0.5,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Derrota',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'loss',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerTwoWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 45],
            player2Stats: ['victory_points' => 48],
        );

        // Player 1 lost but by less than 10 VP, gets bonus
        $this->assertEquals(0.5, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_threshold_less_than(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Derrota apretada',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '<',
                    value: 30.0,
                ),
                points: 0.5,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Derrota',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'loss',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 0.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerTwoWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 25],
            player2Stats: ['victory_points' => 40],
        );

        // Player 1 has VP < 30, gets consolation points
        $this->assertEquals(0.5, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_threshold_equal(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Bonus por empate perfecto',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '==',
                    value: 50.0,
                ),
                points: 2.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Empate',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 1.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::Draw,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 50],
            player2Stats: ['victory_points' => 50],
        );

        // Both players have exactly 50 VP
        $this->assertEquals(2.0, $result['player1']);
        $this->assertEquals(2.0, $result['player2']);
    }

    public function test_it_evaluates_stat_comparison_greater_than(): void
    {
        // StatComparison: player stat > opponent stat (not margin)
        $rules = [
            new ScoringRule(
                name: 'Bonus por más bajas causadas',
                condition: new ScoringCondition(
                    type: ConditionType::StatComparison,
                    resultValue: null,
                    stat: 'casualties',
                    operator: '>',
                    value: null,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['casualties' => 8],
            player2Stats: ['casualties' => 5],
        );

        // Player 1 has more casualties than player 2
        $this->assertEquals(1.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_stat_comparison_less_than(): void
    {
        // StatComparison: player stat < opponent stat
        $rules = [
            new ScoringRule(
                name: 'Bonus por defensa sólida',
                condition: new ScoringCondition(
                    type: ConditionType::StatComparison,
                    resultValue: null,
                    stat: 'casualties_suffered',
                    operator: '<',
                    value: null,
                ),
                points: 1.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['casualties_suffered' => 3],
            player2Stats: ['casualties_suffered' => 6],
        );

        // Player 1 suffered fewer casualties than player 2
        $this->assertEquals(1.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_handles_missing_stat_gracefully(): void
    {
        $rules = [
            new ScoringRule(
                name: 'Bonus por stat inexistente',
                condition: new ScoringCondition(
                    type: ConditionType::StatThreshold,
                    resultValue: null,
                    stat: 'nonexistent_stat',
                    operator: '>=',
                    value: 10.0,
                ),
                points: 5.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Victoria',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
                priority: 0,
            ),
        ];

        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 50],
            player2Stats: ['victory_points' => 30],
        );

        // Missing stat treated as 0, falls through to normal win rule
        $this->assertEquals(3.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }

    public function test_it_evaluates_graduated_scoring_with_multiple_thresholds(): void
    {
        // Warhammer: The Old World graduated scoring system
        // 20+ points: 20-0 VP (full win)
        // 10-19 points: 13-7 VP (minor win)
        // 0-9 points: 10-10 VP (draw)
        $rules = [
            new ScoringRule(
                name: 'Victoria aplastante (20+ VP)',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 20.0,
                ),
                points: 20.0,
                priority: 30,
            ),
            new ScoringRule(
                name: 'Victoria sólida (10-19 VP)',
                condition: new ScoringCondition(
                    type: ConditionType::MarginDifference,
                    resultValue: null,
                    stat: 'victory_points',
                    operator: '>=',
                    value: 10.0,
                ),
                points: 13.0,
                priority: 20,
            ),
            new ScoringRule(
                name: 'Victoria mínima (<10 VP)',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 10.0,
                priority: 10,
            ),
            new ScoringRule(
                name: 'Empate',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 10.0,
                priority: 0,
            ),
        ];

        // Test crushing victory (25 VP margin)
        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 85],
            player2Stats: ['victory_points' => 60],
        );

        $this->assertEquals(20.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);

        // Test solid victory (15 VP margin)
        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 75],
            player2Stats: ['victory_points' => 60],
        );

        $this->assertEquals(13.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);

        // Test minimal victory (5 VP margin)
        $result = $this->evaluator->evaluate(
            result: MatchResult::PlayerOneWin,
            scoringRules: $rules,
            player1Stats: ['victory_points' => 65],
            player2Stats: ['victory_points' => 60],
        );

        $this->assertEquals(10.0, $result['player1']);
        $this->assertEquals(0.0, $result['player2']);
    }
}
