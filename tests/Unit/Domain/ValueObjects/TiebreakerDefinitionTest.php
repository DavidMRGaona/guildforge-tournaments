<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use PHPUnit\Framework\TestCase;

final class TiebreakerDefinitionTest extends TestCase
{
    public function test_it_creates_with_all_properties(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'td_diff',
            name: 'Net TD',
            type: TiebreakerType::StatDiff,
            stat: 'touchdown_diff',
            direction: SortDirection::Descending,
            minValue: 0.0,
        );

        $this->assertEquals('td_diff', $definition->key);
        $this->assertEquals('Net TD', $definition->name);
        $this->assertEquals(TiebreakerType::StatDiff, $definition->type);
        $this->assertEquals('touchdown_diff', $definition->stat);
        $this->assertEquals(SortDirection::Descending, $definition->direction);
        $this->assertEquals(0.0, $definition->minValue);
    }

    public function test_it_creates_with_minimal_properties(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
        );

        $this->assertEquals('buchholz', $definition->key);
        $this->assertEquals('Buchholz', $definition->name);
        $this->assertEquals(TiebreakerType::Buchholz, $definition->type);
        $this->assertNull($definition->stat);
        $this->assertEquals(SortDirection::Descending, $definition->direction);
        $this->assertNull($definition->minValue);
    }

    public function test_it_defaults_to_descending_direction(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
        );

        $this->assertEquals(SortDirection::Descending, $definition->direction);
    }

    public function test_it_allows_ascending_direction(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'losses',
            name: 'Total Losses',
            type: TiebreakerType::StatSum,
            stat: 'losses',
            direction: SortDirection::Ascending,
        );

        $this->assertEquals(SortDirection::Ascending, $definition->direction);
    }

    public function test_it_creates_from_array_with_all_properties(): void
    {
        $definition = TiebreakerDefinition::fromArray([
            'key' => 'td_diff',
            'name' => 'Net TD',
            'type' => 'stat_diff',
            'stat' => 'touchdown_diff',
            'direction' => 'desc',
            'min_value' => 0.0,
        ]);

        $this->assertEquals('td_diff', $definition->key);
        $this->assertEquals('Net TD', $definition->name);
        $this->assertEquals(TiebreakerType::StatDiff, $definition->type);
        $this->assertEquals('touchdown_diff', $definition->stat);
        $this->assertEquals(SortDirection::Descending, $definition->direction);
        $this->assertEquals(0.0, $definition->minValue);
    }

    public function test_it_creates_from_array_with_minimal_properties(): void
    {
        $definition = TiebreakerDefinition::fromArray([
            'key' => 'buchholz',
            'name' => 'Buchholz',
            'type' => 'buchholz',
        ]);

        $this->assertEquals('buchholz', $definition->key);
        $this->assertEquals('Buchholz', $definition->name);
        $this->assertEquals(TiebreakerType::Buchholz, $definition->type);
        $this->assertNull($definition->stat);
        $this->assertEquals(SortDirection::Descending, $definition->direction);
        $this->assertNull($definition->minValue);
    }

    public function test_it_converts_to_array_with_all_properties(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'td_diff',
            name: 'Net TD',
            type: TiebreakerType::StatDiff,
            stat: 'touchdown_diff',
            direction: SortDirection::Descending,
            minValue: 0.0,
        );

        $array = $definition->toArray();

        $this->assertEquals([
            'key' => 'td_diff',
            'name' => 'Net TD',
            'type' => 'stat_diff',
            'stat' => 'touchdown_diff',
            'direction' => 'desc',
            'min_value' => 0.0,
        ], $array);
    }

    public function test_it_converts_to_array_with_null_properties(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
        );

        $array = $definition->toArray();

        $this->assertEquals([
            'key' => 'buchholz',
            'name' => 'Buchholz',
            'type' => 'buchholz',
            'stat' => null,
            'direction' => 'desc',
            'min_value' => null,
        ], $array);
    }

    public function test_it_compares_equality_for_identical_definitions(): void
    {
        $def1 = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
            direction: SortDirection::Descending,
            minValue: 0.33,
        );

        $def2 = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
            direction: SortDirection::Descending,
            minValue: 0.33,
        );

        $this->assertTrue($def1->equals($def2));
    }

    public function test_it_compares_equality_for_different_keys(): void
    {
        $def1 = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
        );

        $def2 = new TiebreakerDefinition(
            key: 'gwp',
            name: 'Game Win %',
            type: TiebreakerType::GameWinPercentage,
        );

        $this->assertFalse($def1->equals($def2));
    }

    public function test_it_compares_equality_for_different_types(): void
    {
        $def1 = new TiebreakerDefinition(
            key: 'score',
            name: 'Score',
            type: TiebreakerType::StatSum,
            stat: 'score',
        );

        $def2 = new TiebreakerDefinition(
            key: 'score',
            name: 'Score',
            type: TiebreakerType::StatAverage,
            stat: 'score',
        );

        $this->assertFalse($def1->equals($def2));
    }

    public function test_it_compares_equality_for_different_directions(): void
    {
        $def1 = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
            direction: SortDirection::Descending,
        );

        $def2 = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
            direction: SortDirection::Ascending,
        );

        $this->assertFalse($def1->equals($def2));
    }

    public function test_it_throws_for_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key cannot be empty');

        new TiebreakerDefinition(
            key: '',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
        );
    }

    public function test_it_throws_for_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new TiebreakerDefinition(
            key: 'buchholz',
            name: '',
            type: TiebreakerType::Buchholz,
        );
    }

    public function test_it_throws_when_stat_sum_missing_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stat is required for stat-based tiebreaker types');

        new TiebreakerDefinition(
            key: 'score',
            name: 'Total Score',
            type: TiebreakerType::StatSum,
        );
    }

    public function test_it_throws_when_stat_diff_missing_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stat is required for stat-based tiebreaker types');

        new TiebreakerDefinition(
            key: 'td_diff',
            name: 'Net TD',
            type: TiebreakerType::StatDiff,
        );
    }

    public function test_it_throws_when_stat_average_missing_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stat is required for stat-based tiebreaker types');

        new TiebreakerDefinition(
            key: 'avg_score',
            name: 'Average Score',
            type: TiebreakerType::StatAverage,
        );
    }

    public function test_it_throws_when_stat_max_missing_stat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stat is required for stat-based tiebreaker types');

        new TiebreakerDefinition(
            key: 'max_score',
            name: 'Max Score',
            type: TiebreakerType::StatMax,
        );
    }

    public function test_it_allows_null_stat_for_buchholz(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
            stat: null,
        );

        $this->assertNull($definition->stat);
    }

    public function test_it_allows_null_stat_for_opponent_win_percentage(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
            stat: null,
        );

        $this->assertNull($definition->stat);
    }

    public function test_it_applies_min_value_correctly(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
            minValue: 0.33,
        );

        $this->assertEquals(0.33, $definition->minValue);
    }

    public function test_it_allows_zero_min_value(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
            minValue: 0.0,
        );

        $this->assertEquals(0.0, $definition->minValue);
    }

    public function test_it_allows_null_min_value(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'buchholz',
            name: 'Buchholz',
            type: TiebreakerType::Buchholz,
            minValue: null,
        );

        $this->assertNull($definition->minValue);
    }

    public function test_it_allows_fractional_min_value(): void
    {
        $definition = new TiebreakerDefinition(
            key: 'omw',
            name: 'Opponent Match Win %',
            type: TiebreakerType::OpponentWinPercentage,
            minValue: 0.333333,
        );

        $this->assertEquals(0.333333, $definition->minValue);
    }
}
