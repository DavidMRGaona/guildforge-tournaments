<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\StatType;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use PHPUnit\Framework\TestCase;

final class StatDefinitionTest extends TestCase
{
    public function test_it_creates_with_all_properties(): void
    {
        $statDefinition = new StatDefinition(
            key: 'touchdowns',
            name: 'Touchdowns',
            type: StatType::Integer,
            minValue: 0,
            maxValue: 10,
            perPlayer: true,
            required: true,
            description: 'Number of touchdowns scored',
        );

        $this->assertEquals('touchdowns', $statDefinition->key);
        $this->assertEquals('Touchdowns', $statDefinition->name);
        $this->assertEquals(StatType::Integer, $statDefinition->type);
        $this->assertEquals(0, $statDefinition->minValue);
        $this->assertEquals(10, $statDefinition->maxValue);
        $this->assertTrue($statDefinition->perPlayer);
        $this->assertTrue($statDefinition->required);
        $this->assertEquals('Number of touchdowns scored', $statDefinition->description);
    }

    public function test_it_creates_with_minimal_properties(): void
    {
        $statDefinition = new StatDefinition(
            key: 'victory_points',
            name: 'Victory Points',
            type: StatType::Float,
        );

        $this->assertEquals('victory_points', $statDefinition->key);
        $this->assertEquals('Victory Points', $statDefinition->name);
        $this->assertEquals(StatType::Float, $statDefinition->type);
        $this->assertNull($statDefinition->minValue);
        $this->assertNull($statDefinition->maxValue);
        $this->assertTrue($statDefinition->perPlayer);
        $this->assertFalse($statDefinition->required);
        $this->assertNull($statDefinition->description);
    }

    public function test_it_creates_from_array(): void
    {
        $statDefinition = StatDefinition::fromArray([
            'key' => 'casualties',
            'name' => 'Casualties',
            'type' => 'integer',
            'min_value' => 0,
            'max_value' => 20,
            'per_player' => true,
            'required' => false,
            'description' => 'Number of casualties inflicted',
        ]);

        $this->assertEquals('casualties', $statDefinition->key);
        $this->assertEquals('Casualties', $statDefinition->name);
        $this->assertEquals(StatType::Integer, $statDefinition->type);
        $this->assertEquals(0, $statDefinition->minValue);
        $this->assertEquals(20, $statDefinition->maxValue);
        $this->assertTrue($statDefinition->perPlayer);
        $this->assertFalse($statDefinition->required);
        $this->assertEquals('Number of casualties inflicted', $statDefinition->description);
    }

    public function test_it_creates_from_array_with_minimal_properties(): void
    {
        $statDefinition = StatDefinition::fromArray([
            'key' => 'painted',
            'name' => 'Painted Army',
            'type' => 'boolean',
        ]);

        $this->assertEquals('painted', $statDefinition->key);
        $this->assertEquals('Painted Army', $statDefinition->name);
        $this->assertEquals(StatType::Boolean, $statDefinition->type);
        $this->assertNull($statDefinition->minValue);
        $this->assertNull($statDefinition->maxValue);
        $this->assertTrue($statDefinition->perPlayer);
        $this->assertFalse($statDefinition->required);
        $this->assertNull($statDefinition->description);
    }

    public function test_it_converts_to_array(): void
    {
        $statDefinition = new StatDefinition(
            key: 'touchdowns',
            name: 'Touchdowns',
            type: StatType::Integer,
            minValue: 0,
            maxValue: 10,
            perPlayer: true,
            required: true,
            description: 'Number of touchdowns scored',
        );

        $array = $statDefinition->toArray();

        $this->assertEquals([
            'key' => 'touchdowns',
            'name' => 'Touchdowns',
            'type' => 'integer',
            'min_value' => 0,
            'max_value' => 10,
            'per_player' => true,
            'required' => true,
            'description' => 'Number of touchdowns scored',
        ], $array);
    }

    public function test_it_converts_to_array_with_minimal_properties(): void
    {
        $statDefinition = new StatDefinition(
            key: 'victory_points',
            name: 'Victory Points',
            type: StatType::Float,
        );

        $array = $statDefinition->toArray();

        $this->assertEquals([
            'key' => 'victory_points',
            'name' => 'Victory Points',
            'type' => 'float',
            'min_value' => null,
            'max_value' => null,
            'per_player' => true,
            'required' => false,
            'description' => null,
        ], $array);
    }

    public function test_it_compares_equality(): void
    {
        $stat1 = new StatDefinition(
            key: 'touchdowns',
            name: 'Touchdowns',
            type: StatType::Integer,
            minValue: 0,
            maxValue: 10,
        );

        $stat2 = new StatDefinition(
            key: 'touchdowns',
            name: 'Touchdowns',
            type: StatType::Integer,
            minValue: 0,
            maxValue: 10,
        );

        $stat3 = new StatDefinition(
            key: 'casualties',
            name: 'Casualties',
            type: StatType::Integer,
        );

        $this->assertTrue($stat1->equals($stat2));
        $this->assertFalse($stat1->equals($stat3));
    }

    public function test_it_throws_for_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key cannot be empty');

        new StatDefinition(
            key: '',
            name: 'Touchdowns',
            type: StatType::Integer,
        );
    }

    public function test_it_throws_for_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new StatDefinition(
            key: 'touchdowns',
            name: '',
            type: StatType::Integer,
        );
    }

    public function test_it_throws_when_min_value_greater_than_max_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimum value cannot be greater than maximum value');

        new StatDefinition(
            key: 'touchdowns',
            name: 'Touchdowns',
            type: StatType::Integer,
            minValue: 10,
            maxValue: 5,
        );
    }

    public function test_it_allows_equal_min_and_max_values(): void
    {
        $statDefinition = new StatDefinition(
            key: 'fixed_value',
            name: 'Fixed Value',
            type: StatType::Integer,
            minValue: 5,
            maxValue: 5,
        );

        $this->assertEquals(5, $statDefinition->minValue);
        $this->assertEquals(5, $statDefinition->maxValue);
    }

    public function test_it_allows_per_player_false(): void
    {
        $statDefinition = new StatDefinition(
            key: 'total_points',
            name: 'Total Points',
            type: StatType::Integer,
            perPlayer: false,
        );

        $this->assertFalse($statDefinition->perPlayer);
    }

    public function test_it_allows_required_true(): void
    {
        $statDefinition = new StatDefinition(
            key: 'result',
            name: 'Result',
            type: StatType::Integer,
            required: true,
        );

        $this->assertTrue($statDefinition->required);
    }
}
