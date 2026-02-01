<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use PHPUnit\Framework\TestCase;

final class ScoringRuleTest extends TestCase
{
    public function test_it_creates_valid_instance_with_all_properties(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $scoringRule = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 10,
        );

        $this->assertEquals('Victoria', $scoringRule->name);
        $this->assertTrue($scoringRule->condition->equals($condition));
        $this->assertEquals(3.0, $scoringRule->points);
        $this->assertEquals(10, $scoringRule->priority);
    }

    public function test_it_creates_with_default_priority(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'draw',
            stat: null,
            operator: null,
            value: null,
        );

        $scoringRule = new ScoringRule(
            name: 'Empate',
            condition: $condition,
            points: 1.0,
        );

        $this->assertEquals(0, $scoringRule->priority);
    }

    public function test_it_creates_from_array(): void
    {
        $scoringRule = ScoringRule::fromArray([
            'name' => 'Victoria aplastante',
            'condition' => [
                'type' => 'stat_threshold',
                'result_value' => null,
                'stat' => 'victory_points',
                'operator' => '>=',
                'value' => 100,
            ],
            'points' => 5,
            'priority' => 20,
        ]);

        $this->assertEquals('Victoria aplastante', $scoringRule->name);
        $this->assertEquals(ConditionType::StatThreshold, $scoringRule->condition->type);
        $this->assertEquals(5.0, $scoringRule->points);
        $this->assertEquals(20, $scoringRule->priority);
    }

    public function test_it_creates_from_array_with_default_priority(): void
    {
        $scoringRule = ScoringRule::fromArray([
            'name' => 'Victoria',
            'condition' => [
                'type' => 'result',
                'result_value' => 'win',
                'stat' => null,
                'operator' => null,
                'value' => null,
            ],
            'points' => 3,
        ]);

        $this->assertEquals(0, $scoringRule->priority);
    }

    public function test_it_converts_to_array(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $scoringRule = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 10,
        );

        $array = $scoringRule->toArray();

        $this->assertEquals([
            'name' => 'Victoria',
            'condition' => [
                'type' => 'result',
                'result_value' => 'win',
                'stat' => null,
                'operator' => null,
                'value' => null,
            ],
            'points' => 3.0,
            'priority' => 10,
        ], $array);
    }

    public function test_it_compares_equality_with_identical_rules(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $rule1 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 10,
        );

        $rule2 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 10,
        );

        $this->assertTrue($rule1->equals($rule2));
    }

    public function test_it_compares_inequality_with_different_names(): void
    {
        $winCondition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $drawCondition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'draw',
            stat: null,
            operator: null,
            value: null,
        );

        $rule1 = new ScoringRule(
            name: 'Victoria',
            condition: $winCondition,
            points: 3.0,
        );

        $rule2 = new ScoringRule(
            name: 'Empate',
            condition: $drawCondition,
            points: 1.0,
        );

        $this->assertFalse($rule1->equals($rule2));
    }

    public function test_it_compares_inequality_with_different_conditions(): void
    {
        $resultCondition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $thresholdCondition = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: 100.0,
        );

        $rule1 = new ScoringRule(
            name: 'Victoria',
            condition: $resultCondition,
            points: 3.0,
        );

        $rule2 = new ScoringRule(
            name: 'Victoria',
            condition: $thresholdCondition,
            points: 3.0,
        );

        $this->assertFalse($rule1->equals($rule2));
    }

    public function test_it_compares_inequality_with_different_points(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $rule1 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
        );

        $rule2 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 5.0,
        );

        $this->assertFalse($rule1->equals($rule2));
    }

    public function test_it_compares_inequality_with_different_priority(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $rule1 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 10,
        );

        $rule2 = new ScoringRule(
            name: 'Victoria',
            condition: $condition,
            points: 3.0,
            priority: 5,
        );

        $this->assertFalse($rule1->equals($rule2));
    }

    public function test_it_throws_for_empty_name(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new ScoringRule(
            name: '',
            condition: $condition,
            points: 3.0,
        );
    }

    public function test_it_throws_for_negative_points(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points cannot be negative');

        new ScoringRule(
            name: 'Invalid',
            condition: $condition,
            points: -1.0,
        );
    }

    public function test_it_allows_zero_points(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'loss',
            stat: null,
            operator: null,
            value: null,
        );

        $scoringRule = new ScoringRule(
            name: 'Derrota',
            condition: $condition,
            points: 0.0,
        );

        $this->assertEquals(0.0, $scoringRule->points);
    }

    public function test_it_allows_fractional_points(): void
    {
        $condition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'draw',
            stat: null,
            operator: null,
            value: null,
        );

        $scoringRule = new ScoringRule(
            name: 'Empate',
            condition: $condition,
            points: 0.5,
        );

        $this->assertEquals(0.5, $scoringRule->points);
    }

    public function test_it_sorts_by_priority_higher_first(): void
    {
        $winCondition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'win',
            stat: null,
            operator: null,
            value: null,
        );

        $thresholdCondition = new ScoringCondition(
            type: ConditionType::StatThreshold,
            resultValue: null,
            stat: 'victory_points',
            operator: '>=',
            value: 100.0,
        );

        $drawCondition = new ScoringCondition(
            type: ConditionType::Result,
            resultValue: 'draw',
            stat: null,
            operator: null,
            value: null,
        );

        $rules = [
            new ScoringRule('Low', $winCondition, 3.0, priority: 5),
            new ScoringRule('High', $thresholdCondition, 5.0, priority: 20),
            new ScoringRule('Medium', $winCondition, 3.0, priority: 10),
            new ScoringRule('Zero', $drawCondition, 1.0, priority: 0),
        ];

        usort($rules, fn (ScoringRule $a, ScoringRule $b) => $b->priority <=> $a->priority);

        $this->assertEquals('High', $rules[0]->name);
        $this->assertEquals('Medium', $rules[1]->name);
        $this->assertEquals('Low', $rules[2]->name);
        $this->assertEquals('Zero', $rules[3]->name);
    }
}
