<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Tests\TestCase;

final class TiebreakerTypeTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = TiebreakerType::cases();

        $this->assertCount(16, $cases);

        // Classic Swiss tiebreakers
        $this->assertContains(TiebreakerType::Buchholz, $cases);
        $this->assertContains(TiebreakerType::MedianBuchholz, $cases);
        $this->assertContains(TiebreakerType::Progressive, $cases);
        $this->assertContains(TiebreakerType::OpponentWinPercentage, $cases);
        $this->assertContains(TiebreakerType::OpponentOpponentWinPercentage, $cases);
        $this->assertContains(TiebreakerType::GameWinPercentage, $cases);
        $this->assertContains(TiebreakerType::OpponentGameWinPercentage, $cases);
        $this->assertContains(TiebreakerType::HeadToHead, $cases);
        $this->assertContains(TiebreakerType::SonnebornBerger, $cases);

        // Stat-based tiebreakers
        $this->assertContains(TiebreakerType::StatSum, $cases);
        $this->assertContains(TiebreakerType::StatDiff, $cases);
        $this->assertContains(TiebreakerType::StatAverage, $cases);
        $this->assertContains(TiebreakerType::StatMax, $cases);

        // Special
        $this->assertContains(TiebreakerType::StrengthOfSchedule, $cases);
        $this->assertContains(TiebreakerType::MarginOfVictory, $cases);
        $this->assertContains(TiebreakerType::Random, $cases);
    }

    public function test_buchholz_has_correct_value(): void
    {
        $this->assertEquals('buchholz', TiebreakerType::Buchholz->value);
    }

    public function test_median_buchholz_has_correct_value(): void
    {
        $this->assertEquals('median_buchholz', TiebreakerType::MedianBuchholz->value);
    }

    public function test_progressive_has_correct_value(): void
    {
        $this->assertEquals('progressive', TiebreakerType::Progressive->value);
    }

    public function test_opponent_win_percentage_has_correct_value(): void
    {
        $this->assertEquals('owp', TiebreakerType::OpponentWinPercentage->value);
    }

    public function test_opponent_opponent_win_percentage_has_correct_value(): void
    {
        $this->assertEquals('oowp', TiebreakerType::OpponentOpponentWinPercentage->value);
    }

    public function test_game_win_percentage_has_correct_value(): void
    {
        $this->assertEquals('gwp', TiebreakerType::GameWinPercentage->value);
    }

    public function test_opponent_game_win_percentage_has_correct_value(): void
    {
        $this->assertEquals('ogwp', TiebreakerType::OpponentGameWinPercentage->value);
    }

    public function test_head_to_head_has_correct_value(): void
    {
        $this->assertEquals('head_to_head', TiebreakerType::HeadToHead->value);
    }

    public function test_sonneborn_berger_has_correct_value(): void
    {
        $this->assertEquals('sonneborn_berger', TiebreakerType::SonnebornBerger->value);
    }

    public function test_stat_sum_has_correct_value(): void
    {
        $this->assertEquals('stat_sum', TiebreakerType::StatSum->value);
    }

    public function test_stat_diff_has_correct_value(): void
    {
        $this->assertEquals('stat_diff', TiebreakerType::StatDiff->value);
    }

    public function test_stat_average_has_correct_value(): void
    {
        $this->assertEquals('stat_average', TiebreakerType::StatAverage->value);
    }

    public function test_stat_max_has_correct_value(): void
    {
        $this->assertEquals('stat_max', TiebreakerType::StatMax->value);
    }

    public function test_strength_of_schedule_has_correct_value(): void
    {
        $this->assertEquals('sos', TiebreakerType::StrengthOfSchedule->value);
    }

    public function test_margin_of_victory_has_correct_value(): void
    {
        $this->assertEquals('mov', TiebreakerType::MarginOfVictory->value);
    }

    public function test_random_has_correct_value(): void
    {
        $this->assertEquals('random', TiebreakerType::Random->value);
    }

    public function test_values_returns_all_case_values(): void
    {
        $values = TiebreakerType::values();

        $this->assertIsArray($values);
        $this->assertCount(16, $values);

        // Classic Swiss
        $this->assertContains('buchholz', $values);
        $this->assertContains('median_buchholz', $values);
        $this->assertContains('progressive', $values);
        $this->assertContains('owp', $values);
        $this->assertContains('oowp', $values);
        $this->assertContains('gwp', $values);
        $this->assertContains('ogwp', $values);
        $this->assertContains('head_to_head', $values);
        $this->assertContains('sonneborn_berger', $values);

        // Stat-based
        $this->assertContains('stat_sum', $values);
        $this->assertContains('stat_diff', $values);
        $this->assertContains('stat_average', $values);
        $this->assertContains('stat_max', $values);

        // Special
        $this->assertContains('sos', $values);
        $this->assertContains('mov', $values);
        $this->assertContains('random', $values);
    }

    public function test_options_returns_label_value_pairs(): void
    {
        $options = TiebreakerType::options();

        $this->assertIsArray($options);
        $this->assertCount(16, $options);

        // Check structure (value => label)
        foreach ($options as $value => $label) {
            $this->assertIsString($value);
            $this->assertIsString($label);
        }

        // Check specific option exists
        $this->assertArrayHasKey('buchholz', $options);
        $this->assertArrayHasKey('stat_sum', $options);
        $this->assertArrayHasKey('random', $options);
    }

    public function test_label_returns_translated_string_for_buchholz(): void
    {
        $label = TiebreakerType::Buchholz->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_median_buchholz(): void
    {
        $label = TiebreakerType::MedianBuchholz->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_progressive(): void
    {
        $label = TiebreakerType::Progressive->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_opponent_win_percentage(): void
    {
        $label = TiebreakerType::OpponentWinPercentage->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_stat_sum(): void
    {
        $label = TiebreakerType::StatSum->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_random(): void
    {
        $label = TiebreakerType::Random->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_requires_stat_returns_true_for_stat_sum(): void
    {
        $this->assertTrue(TiebreakerType::StatSum->requiresStat());
    }

    public function test_requires_stat_returns_true_for_stat_diff(): void
    {
        $this->assertTrue(TiebreakerType::StatDiff->requiresStat());
    }

    public function test_requires_stat_returns_true_for_stat_average(): void
    {
        $this->assertTrue(TiebreakerType::StatAverage->requiresStat());
    }

    public function test_requires_stat_returns_true_for_stat_max(): void
    {
        $this->assertTrue(TiebreakerType::StatMax->requiresStat());
    }

    public function test_requires_stat_returns_false_for_buchholz(): void
    {
        $this->assertFalse(TiebreakerType::Buchholz->requiresStat());
    }

    public function test_requires_stat_returns_false_for_median_buchholz(): void
    {
        $this->assertFalse(TiebreakerType::MedianBuchholz->requiresStat());
    }

    public function test_requires_stat_returns_false_for_progressive(): void
    {
        $this->assertFalse(TiebreakerType::Progressive->requiresStat());
    }

    public function test_requires_stat_returns_false_for_opponent_win_percentage(): void
    {
        $this->assertFalse(TiebreakerType::OpponentWinPercentage->requiresStat());
    }

    public function test_requires_stat_returns_false_for_opponent_opponent_win_percentage(): void
    {
        $this->assertFalse(TiebreakerType::OpponentOpponentWinPercentage->requiresStat());
    }

    public function test_requires_stat_returns_false_for_game_win_percentage(): void
    {
        $this->assertFalse(TiebreakerType::GameWinPercentage->requiresStat());
    }

    public function test_requires_stat_returns_false_for_opponent_game_win_percentage(): void
    {
        $this->assertFalse(TiebreakerType::OpponentGameWinPercentage->requiresStat());
    }

    public function test_requires_stat_returns_false_for_head_to_head(): void
    {
        $this->assertFalse(TiebreakerType::HeadToHead->requiresStat());
    }

    public function test_requires_stat_returns_false_for_sonneborn_berger(): void
    {
        $this->assertFalse(TiebreakerType::SonnebornBerger->requiresStat());
    }

    public function test_requires_stat_returns_false_for_strength_of_schedule(): void
    {
        $this->assertFalse(TiebreakerType::StrengthOfSchedule->requiresStat());
    }

    public function test_requires_stat_returns_false_for_margin_of_victory(): void
    {
        $this->assertFalse(TiebreakerType::MarginOfVictory->requiresStat());
    }

    public function test_requires_stat_returns_false_for_random(): void
    {
        $this->assertFalse(TiebreakerType::Random->requiresStat());
    }
}
