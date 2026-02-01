<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\ConditionType;
use Tests\TestCase;

final class ConditionTypeTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = ConditionType::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(ConditionType::Result, $cases);
        $this->assertContains(ConditionType::StatComparison, $cases);
        $this->assertContains(ConditionType::StatThreshold, $cases);
        $this->assertContains(ConditionType::MarginDifference, $cases);
    }

    public function test_result_has_correct_value(): void
    {
        $this->assertEquals('result', ConditionType::Result->value);
    }

    public function test_stat_comparison_has_correct_value(): void
    {
        $this->assertEquals('stat_comparison', ConditionType::StatComparison->value);
    }

    public function test_stat_threshold_has_correct_value(): void
    {
        $this->assertEquals('stat_threshold', ConditionType::StatThreshold->value);
    }

    public function test_margin_difference_has_correct_value(): void
    {
        $this->assertEquals('margin_diff', ConditionType::MarginDifference->value);
    }

    public function test_values_returns_string_values(): void
    {
        $values = ConditionType::values();

        $this->assertContains('result', $values);
        $this->assertContains('stat_comparison', $values);
        $this->assertContains('stat_threshold', $values);
        $this->assertContains('margin_diff', $values);
    }

    public function test_options_returns_label_value_pairs(): void
    {
        $options = ConditionType::options();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('result', $options);
        $this->assertArrayHasKey('stat_comparison', $options);
        $this->assertArrayHasKey('stat_threshold', $options);
        $this->assertArrayHasKey('margin_diff', $options);
    }

    public function test_label_returns_translated_string_for_result(): void
    {
        $label = ConditionType::Result->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_stat_comparison(): void
    {
        $label = ConditionType::StatComparison->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_stat_threshold(): void
    {
        $label = ConditionType::StatThreshold->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_label_returns_translated_string_for_margin_difference(): void
    {
        $label = ConditionType::MarginDifference->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }
}
