<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use PHPUnit\Framework\TestCase;

final class PairingConfigTest extends TestCase
{
    public function test_it_creates_with_all_properties(): void
    {
        $config = new PairingConfig(
            method: PairingMethod::Swiss,
            sortBy: PairingSortCriteria::Points,
            sortByStat: null,
            avoidRematches: true,
            maxByesPerPlayer: 1,
            byeAssignment: ByeAssignment::LowestRanked,
        );

        $this->assertEquals(PairingMethod::Swiss, $config->method);
        $this->assertEquals(PairingSortCriteria::Points, $config->sortBy);
        $this->assertNull($config->sortByStat);
        $this->assertTrue($config->avoidRematches);
        $this->assertEquals(1, $config->maxByesPerPlayer);
        $this->assertEquals(ByeAssignment::LowestRanked, $config->byeAssignment);
    }

    public function test_it_creates_with_all_defaults(): void
    {
        $config = new PairingConfig();

        $this->assertEquals(PairingMethod::Swiss, $config->method);
        $this->assertEquals(PairingSortCriteria::Points, $config->sortBy);
        $this->assertNull($config->sortByStat);
        $this->assertTrue($config->avoidRematches);
        $this->assertEquals(1, $config->maxByesPerPlayer);
        $this->assertEquals(ByeAssignment::LowestRanked, $config->byeAssignment);
    }

    public function test_it_creates_with_custom_values(): void
    {
        $config = new PairingConfig(
            method: PairingMethod::Accelerated,
            sortBy: PairingSortCriteria::Stat,
            sortByStat: 'strength_of_schedule',
            avoidRematches: false,
            maxByesPerPlayer: 2,
            byeAssignment: ByeAssignment::Random,
        );

        $this->assertEquals(PairingMethod::Accelerated, $config->method);
        $this->assertEquals(PairingSortCriteria::Stat, $config->sortBy);
        $this->assertEquals('strength_of_schedule', $config->sortByStat);
        $this->assertFalse($config->avoidRematches);
        $this->assertEquals(2, $config->maxByesPerPlayer);
        $this->assertEquals(ByeAssignment::Random, $config->byeAssignment);
    }

    public function test_it_creates_from_array(): void
    {
        $config = PairingConfig::fromArray([
            'method' => 'swiss',
            'sort_by' => 'points',
            'sort_by_stat' => null,
            'avoid_rematches' => true,
            'max_byes_per_player' => 1,
            'bye_assignment' => 'lowest_ranked',
        ]);

        $this->assertEquals(PairingMethod::Swiss, $config->method);
        $this->assertEquals(PairingSortCriteria::Points, $config->sortBy);
        $this->assertNull($config->sortByStat);
        $this->assertTrue($config->avoidRematches);
        $this->assertEquals(1, $config->maxByesPerPlayer);
        $this->assertEquals(ByeAssignment::LowestRanked, $config->byeAssignment);
    }

    public function test_it_creates_from_array_with_stat_sorting(): void
    {
        $config = PairingConfig::fromArray([
            'method' => 'accelerated',
            'sort_by' => 'stat',
            'sort_by_stat' => 'wins',
            'avoid_rematches' => false,
            'max_byes_per_player' => 2,
            'bye_assignment' => 'random',
        ]);

        $this->assertEquals(PairingMethod::Accelerated, $config->method);
        $this->assertEquals(PairingSortCriteria::Stat, $config->sortBy);
        $this->assertEquals('wins', $config->sortByStat);
        $this->assertFalse($config->avoidRematches);
        $this->assertEquals(2, $config->maxByesPerPlayer);
        $this->assertEquals(ByeAssignment::Random, $config->byeAssignment);
    }

    public function test_it_converts_to_array(): void
    {
        $config = new PairingConfig(
            method: PairingMethod::Swiss,
            sortBy: PairingSortCriteria::Points,
            sortByStat: null,
            avoidRematches: true,
            maxByesPerPlayer: 1,
            byeAssignment: ByeAssignment::LowestRanked,
        );

        $array = $config->toArray();

        $this->assertEquals([
            'method' => 'swiss',
            'sort_by' => 'points',
            'sort_by_stat' => null,
            'avoid_rematches' => true,
            'max_byes_per_player' => 1,
            'bye_assignment' => 'lowest_ranked',
        ], $array);
    }

    public function test_it_converts_to_array_with_stat_sorting(): void
    {
        $config = new PairingConfig(
            method: PairingMethod::Accelerated,
            sortBy: PairingSortCriteria::Stat,
            sortByStat: 'strength_of_schedule',
            avoidRematches: false,
            maxByesPerPlayer: 2,
            byeAssignment: ByeAssignment::Random,
        );

        $array = $config->toArray();

        $this->assertEquals([
            'method' => 'accelerated',
            'sort_by' => 'stat',
            'sort_by_stat' => 'strength_of_schedule',
            'avoid_rematches' => false,
            'max_byes_per_player' => 2,
            'bye_assignment' => 'random',
        ], $array);
    }

    public function test_it_compares_equality_with_identical_configs(): void
    {
        $config1 = new PairingConfig(
            method: PairingMethod::Swiss,
            sortBy: PairingSortCriteria::Points,
            sortByStat: null,
            avoidRematches: true,
            maxByesPerPlayer: 1,
            byeAssignment: ByeAssignment::LowestRanked,
        );

        $config2 = new PairingConfig(
            method: PairingMethod::Swiss,
            sortBy: PairingSortCriteria::Points,
            sortByStat: null,
            avoidRematches: true,
            maxByesPerPlayer: 1,
            byeAssignment: ByeAssignment::LowestRanked,
        );

        $this->assertTrue($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_methods(): void
    {
        $config1 = new PairingConfig(method: PairingMethod::Swiss);
        $config2 = new PairingConfig(method: PairingMethod::Random);

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_sort_criteria(): void
    {
        $config1 = new PairingConfig(sortBy: PairingSortCriteria::Points);
        $config2 = new PairingConfig(sortBy: PairingSortCriteria::Random);

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_sort_by_stat(): void
    {
        $config1 = new PairingConfig(
            sortBy: PairingSortCriteria::Stat,
            sortByStat: 'wins',
        );
        $config2 = new PairingConfig(
            sortBy: PairingSortCriteria::Stat,
            sortByStat: 'losses',
        );

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_avoid_rematches(): void
    {
        $config1 = new PairingConfig(avoidRematches: true);
        $config2 = new PairingConfig(avoidRematches: false);

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_max_byes(): void
    {
        $config1 = new PairingConfig(maxByesPerPlayer: 1);
        $config2 = new PairingConfig(maxByesPerPlayer: 2);

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_compares_equality_with_different_bye_assignment(): void
    {
        $config1 = new PairingConfig(byeAssignment: ByeAssignment::LowestRanked);
        $config2 = new PairingConfig(byeAssignment: ByeAssignment::Random);

        $this->assertFalse($config1->equals($config2));
    }

    public function test_it_throws_when_sort_by_stat_requires_stat_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sortByStat is required when sortBy is Stat');

        new PairingConfig(
            sortBy: PairingSortCriteria::Stat,
            sortByStat: null,
        );
    }

    public function test_it_throws_when_sort_by_stat_is_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sortByStat is required when sortBy is Stat');

        new PairingConfig(
            sortBy: PairingSortCriteria::Stat,
            sortByStat: '',
        );
    }

    public function test_it_throws_when_max_byes_per_player_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxByesPerPlayer cannot be negative');

        new PairingConfig(maxByesPerPlayer: -1);
    }

    public function test_it_allows_zero_max_byes_per_player(): void
    {
        $config = new PairingConfig(maxByesPerPlayer: 0);

        $this->assertEquals(0, $config->maxByesPerPlayer);
    }

    public function test_it_allows_high_max_byes_per_player(): void
    {
        $config = new PairingConfig(maxByesPerPlayer: 10);

        $this->assertEquals(10, $config->maxByesPerPlayer);
    }

    public function test_it_allows_null_sort_by_stat_when_not_using_stat_sorting(): void
    {
        $config = new PairingConfig(
            sortBy: PairingSortCriteria::Points,
            sortByStat: null,
        );

        $this->assertNull($config->sortByStat);
    }
}
