<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\Tiebreaker;
use PHPUnit\Framework\TestCase;

final class TiebreakerTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = Tiebreaker::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(Tiebreaker::Buchholz, $cases);
        $this->assertContains(Tiebreaker::MedianBuchholz, $cases);
        $this->assertContains(Tiebreaker::Progressive, $cases);
        $this->assertContains(Tiebreaker::HeadToHead, $cases);
        $this->assertContains(Tiebreaker::OpponentWinPercentage, $cases);
    }

    public function test_values_returns_string_values(): void
    {
        $values = Tiebreaker::values();

        $this->assertContains('buchholz', $values);
        $this->assertContains('median_buchholz', $values);
        $this->assertContains('progressive', $values);
        $this->assertContains('head_to_head', $values);
        $this->assertContains('opponent_win_percentage', $values);
    }

    public function test_default_tiebreakers_are_supported(): void
    {
        // Verify the most commonly used tiebreakers exist
        $this->assertEquals('buchholz', Tiebreaker::Buchholz->value);
        $this->assertEquals('median_buchholz', Tiebreaker::MedianBuchholz->value);
        $this->assertEquals('progressive', Tiebreaker::Progressive->value);
        $this->assertEquals('head_to_head', Tiebreaker::HeadToHead->value);
        $this->assertEquals('opponent_win_percentage', Tiebreaker::OpponentWinPercentage->value);
    }

    public function test_from_array_returns_tiebreaker_instances(): void
    {
        $tiebreakers = Tiebreaker::fromArray(['buchholz', 'progressive']);

        $this->assertCount(2, $tiebreakers);
        $this->assertEquals(Tiebreaker::Buchholz, $tiebreakers[0]);
        $this->assertEquals(Tiebreaker::Progressive, $tiebreakers[1]);
    }

    public function test_to_values_returns_string_array(): void
    {
        $tiebreakers = [Tiebreaker::Buchholz, Tiebreaker::Progressive];
        $values = Tiebreaker::toValues($tiebreakers);

        $this->assertEquals(['buchholz', 'progressive'], $values);
    }
}
