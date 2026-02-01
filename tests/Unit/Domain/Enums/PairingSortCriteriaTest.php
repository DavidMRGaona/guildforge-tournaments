<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Tests\TestCase;

final class PairingSortCriteriaTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = PairingSortCriteria::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(PairingSortCriteria::Points, $cases);
        $this->assertContains(PairingSortCriteria::Stat, $cases);
        $this->assertContains(PairingSortCriteria::Random, $cases);
    }

    public function test_points_case_has_correct_value(): void
    {
        $this->assertEquals('points', PairingSortCriteria::Points->value);
    }

    public function test_stat_case_has_correct_value(): void
    {
        $this->assertEquals('stat', PairingSortCriteria::Stat->value);
    }

    public function test_random_case_has_correct_value(): void
    {
        $this->assertEquals('random', PairingSortCriteria::Random->value);
    }

    public function test_values_returns_all_case_values(): void
    {
        $values = PairingSortCriteria::values();

        $this->assertCount(3, $values);
        $this->assertContains('points', $values);
        $this->assertContains('stat', $values);
        $this->assertContains('random', $values);
    }

    public function test_options_returns_label_value_pairs(): void
    {
        $options = PairingSortCriteria::options();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('points', $options);
        $this->assertArrayHasKey('stat', $options);
        $this->assertArrayHasKey('random', $options);
        $this->assertCount(3, $options);
    }

    public function test_label_returns_translated_string_for_points(): void
    {
        $label = PairingSortCriteria::Points->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_stat(): void
    {
        $label = PairingSortCriteria::Stat->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_random(): void
    {
        $label = PairingSortCriteria::Random->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }
}
