<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use PHPUnit\Framework\TestCase;

final class ScoringConditionTest extends TestCase
{
    public function test_it_creates_result_condition(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertEquals(ConditionType::Result, $condition->type);
        $this->assertEquals('win', $condition->resultValue);
        $this->assertNull($condition->stat);
        $this->assertNull($condition->operator);
        $this->assertNull($condition->value);
    }

    public function test_it_creates_stat_comparison_condition(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::StatComparison,
            resultValue: null,
            stat: 'touchdowns',
            operator: '>',
            value: null,
        );

        $this->assertEquals(ConditionType::StatComparison, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('touchdowns', $condition->stat);
        $this->assertEquals('>', $condition->operator);
        $this->assertNull($condition->value);
    }

    public function test_it_creates_stat_threshold_condition(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: 100.0,
        );

        $this->assertEquals(ConditionType::StatThreshold, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('victory_points', $condition->stat);
        $this->assertEquals('>=', $condition->operator);
        $this->assertEquals(100.0, $condition->value);
    }

    public function test_it_creates_margin_difference_condition(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::MarginDifference,
            resultValue: null,
            stat: 'victory_points',
            operator: 'diff>',
            value: 50.0,
        );

        $this->assertEquals(ConditionType::MarginDifference, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('victory_points', $condition->stat);
        $this->assertEquals('diff>', $condition->operator);
        $this->assertEquals(50.0, $condition->value);
    }

    public function test_it_creates_from_array_for_result_type(): void
    {
        $condition = ScoringCondition::fromArray([
            'type' => 'result',
            'result_value' => 'draw',
            'stat' => null,
            'operator' => null,
            'value' => null,
        ]);

        $this->assertEquals(ConditionType::Result, $condition->type);
        $this->assertEquals('draw', $condition->resultValue);
        $this->assertNull($condition->stat);
        $this->assertNull($condition->operator);
        $this->assertNull($condition->value);
    }

    public function test_it_creates_from_array_for_stat_comparison_type(): void
    {
        $condition = ScoringCondition::fromArray([
            'type' => 'stat_comparison',
            'result_value' => null,
            'stat' => 'touchdowns',
            'operator' => '<',
            'value' => null,
        ]);

        $this->assertEquals(ConditionType::StatComparison, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('touchdowns', $condition->stat);
        $this->assertEquals('<', $condition->operator);
        $this->assertNull($condition->value);
    }

    public function test_it_creates_from_array_for_stat_threshold_type(): void
    {
        $condition = ScoringCondition::fromArray([
            'type' => 'stat_threshold',
            'result_value' => null,
            'stat' => 'victory_points',
            'operator' => '==',
            'value' => 75,
        ]);

        $this->assertEquals(ConditionType::StatThreshold, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('victory_points', $condition->stat);
        $this->assertEquals('==', $condition->operator);
        $this->assertEquals(75.0, $condition->value);
    }

    public function test_it_creates_from_array_for_margin_difference_type(): void
    {
        $condition = ScoringCondition::fromArray([
            'type' => 'margin_diff',
            'result_value' => null,
            'stat' => 'victory_points',
            'operator' => 'diff>',
            'value' => 25.5,
        ]);

        $this->assertEquals(ConditionType::MarginDifference, $condition->type);
        $this->assertNull($condition->resultValue);
        $this->assertEquals('victory_points', $condition->stat);
        $this->assertEquals('diff>', $condition->operator);
        $this->assertEquals(25.5, $condition->value);
    }

    public function test_it_converts_to_array_for_result_type(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $array = $condition->toArray();

        $this->assertEquals([
            'type' => 'result',
            'result_value' => 'win',
            'stat' => null,
            'operator' => null,
            'value' => null,
        ], $array);
    }

    public function test_it_converts_to_array_for_stat_comparison_type(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::StatComparison,
            resultValue: null,
            stat: 'touchdowns',
            operator: '>=',
            value: null,
        );

        $array = $condition->toArray();

        $this->assertEquals([
            'type' => 'stat_comparison',
            'result_value' => null,
            'stat' => 'touchdowns',
            'operator' => '>=',
            'value' => null,
        ], $array);
    }

    public function test_it_converts_to_array_for_stat_threshold_type(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '<=',
            value: 200.0,
        );

        $array = $condition->toArray();

        $this->assertEquals([
            'type' => 'stat_threshold',
            'result_value' => null,
            'stat' => 'victory_points',
            'operator' => '<=',
            'value' => 200.0,
        ], $array);
    }

    public function test_it_converts_to_array_for_margin_difference_type(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::MarginDifference,
            resultValue: null,
            stat: 'victory_points',
            operator: 'diff>',
            value: 30.0,
        );

        $array = $condition->toArray();

        $this->assertEquals([
            'type' => 'margin_diff',
            'result_value' => null,
            'stat' => 'victory_points',
            'operator' => 'diff>',
            'value' => 30.0,
        ], $array);
    }

    public function test_it_compares_equality_for_same_result_conditions(): void
    {
        $condition1 = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $condition2 = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertTrue($condition1->equals($condition2));
    }

    public function test_it_compares_equality_for_different_conditions(): void
    {
        $condition1 = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $condition2 = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'loss',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertFalse($condition1->equals($condition2));
    }

    public function test_it_compares_equality_for_same_stat_threshold_conditions(): void
    {
        $condition1 = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: 100.0,
        );

        $condition2 = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: 100.0,
        );

        $this->assertTrue($condition1->equals($condition2));
    }

    public function test_it_throws_for_result_type_without_result_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Result type requires resultValue');

        new ScoringCondition(
            type: ConditionType::Result,
            resultValue: null,
            stat: null,
            operator: null,
            value: null,
        );
    }

    public function test_it_throws_for_stat_comparison_without_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StatComparison type requires stat and operator');

        new ScoringCondition(
            type: ConditionType::StatComparison,
            resultValue: null,
            stat: null,
            operator: '>',
            value: null,
        );
    }

    public function test_it_throws_for_stat_comparison_without_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StatComparison type requires stat and operator');

        new ScoringCondition(
            type: ConditionType::StatComparison,
            resultValue: null,
            stat: 'touchdowns',
            operator: null,
            value: null,
        );
    }

    public function test_it_throws_for_stat_threshold_without_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StatThreshold type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: null,
            operator: '>=',
            value: 100.0,
        );
    }

    public function test_it_throws_for_stat_threshold_without_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StatThreshold type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: null,
            value: 100.0,
        );
    }

    public function test_it_throws_for_stat_threshold_without_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StatThreshold type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: null,
        );
    }

    public function test_it_throws_for_margin_difference_without_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MarginDifference type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::MarginDifference,
            resultValue: null,
            stat: null,
            operator: 'diff>',
            value: 50.0,
        );
    }

    public function test_it_throws_for_margin_difference_without_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MarginDifference type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::MarginDifference,
            resultValue: null,
            stat: 'victory_points',
            operator: null,
            value: 50.0,
        );
    }

    public function test_it_throws_for_margin_difference_without_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MarginDifference type requires stat, operator, and value');

        new ScoringCondition(
            type: ConditionType::MarginDifference,
            resultValue: null,
            stat: 'victory_points',
            operator: 'diff>',
            value: null,
        );
    }

    public function test_it_throws_for_result_type_with_invalid_result_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid result value. Must be one of: win, draw, loss, bye');

        new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'invalid',
            stat: null,
            operator: null,
            value: null,
        );
    }

    public function test_it_accepts_win_as_result_value(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertEquals('win', $condition->resultValue);
    }

    public function test_it_accepts_draw_as_result_value(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'draw',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertEquals('draw', $condition->resultValue);
    }

    public function test_it_accepts_loss_as_result_value(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'loss',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertEquals('loss', $condition->resultValue);
    }

    public function test_it_accepts_bye_as_result_value(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'bye',
            stat: null,
            operator: null,
            value: null,
        );

        $this->assertEquals('bye', $condition->resultValue);
    }
}
