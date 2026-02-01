<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use PHPUnit\Framework\TestCase;

final class ScoreWeightTest extends TestCase
{
    public function test_it_creates_with_valid_values(): void
    {
        $scoreWeight = new ScoreWeight(
            name: 'Victoria',
            key: 'win',
            points: 3.0,
        );

        $this->assertEquals('Victoria', $scoreWeight->name);
        $this->assertEquals('win', $scoreWeight->key);
        $this->assertEquals(3.0, $scoreWeight->points);
    }

    public function test_it_allows_zero_points(): void
    {
        $scoreWeight = new ScoreWeight(
            name: 'Derrota',
            key: 'loss',
            points: 0.0,
        );

        $this->assertEquals(0.0, $scoreWeight->points);
    }

    public function test_it_allows_fractional_points(): void
    {
        $scoreWeight = new ScoreWeight(
            name: 'Empate',
            key: 'draw',
            points: 0.5,
        );

        $this->assertEquals(0.5, $scoreWeight->points);
    }

    public function test_it_throws_for_negative_points(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points cannot be negative');

        new ScoreWeight(
            name: 'Invalid',
            key: 'invalid',
            points: -1.0,
        );
    }

    public function test_it_throws_for_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name cannot be empty');

        new ScoreWeight(
            name: '',
            key: 'win',
            points: 3.0,
        );
    }

    public function test_it_throws_for_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Key cannot be empty');

        new ScoreWeight(
            name: 'Victoria',
            key: '',
            points: 3.0,
        );
    }

    public function test_it_creates_from_array(): void
    {
        $scoreWeight = ScoreWeight::fromArray([
            'name' => 'Victoria',
            'key' => 'win',
            'points' => 3,
        ]);

        $this->assertEquals('Victoria', $scoreWeight->name);
        $this->assertEquals('win', $scoreWeight->key);
        $this->assertEquals(3.0, $scoreWeight->points);
    }

    public function test_it_converts_to_array(): void
    {
        $scoreWeight = new ScoreWeight(
            name: 'Victoria',
            key: 'win',
            points: 3.0,
        );

        $array = $scoreWeight->toArray();

        $this->assertEquals([
            'name' => 'Victoria',
            'key' => 'win',
            'points' => 3.0,
        ], $array);
    }

    public function test_it_compares_equality(): void
    {
        $sw1 = new ScoreWeight('Victoria', 'win', 3.0);
        $sw2 = new ScoreWeight('Victoria', 'win', 3.0);
        $sw3 = new ScoreWeight('Empate', 'draw', 1.0);

        $this->assertTrue($sw1->equals($sw2));
        $this->assertFalse($sw1->equals($sw3));
    }
}
