<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\StatType;
use Tests\TestCase;

final class StatTypeTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = StatType::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(StatType::Integer, $cases);
        $this->assertContains(StatType::Float, $cases);
        $this->assertContains(StatType::Boolean, $cases);
    }

    public function test_integer_has_correct_value(): void
    {
        $this->assertEquals('integer', StatType::Integer->value);
    }

    public function test_float_has_correct_value(): void
    {
        $this->assertEquals('float', StatType::Float->value);
    }

    public function test_boolean_has_correct_value(): void
    {
        $this->assertEquals('boolean', StatType::Boolean->value);
    }

    public function test_values_returns_string_values(): void
    {
        $values = StatType::values();

        $this->assertCount(3, $values);
        $this->assertContains('integer', $values);
        $this->assertContains('float', $values);
        $this->assertContains('boolean', $values);
    }

    public function test_label_returns_translated_label(): void
    {
        $this->assertEquals(
            __('tournaments::messages.stat_type.integer'),
            StatType::Integer->label()
        );
        $this->assertEquals(
            __('tournaments::messages.stat_type.float'),
            StatType::Float->label()
        );
        $this->assertEquals(
            __('tournaments::messages.stat_type.boolean'),
            StatType::Boolean->label()
        );
    }

    public function test_options_returns_value_label_pairs(): void
    {
        $options = StatType::options();

        $this->assertIsArray($options);
        $this->assertCount(3, $options);
        $this->assertArrayHasKey('integer', $options);
        $this->assertArrayHasKey('float', $options);
        $this->assertArrayHasKey('boolean', $options);

        $this->assertEquals(
            __('tournaments::messages.stat_type.integer'),
            $options['integer']
        );
        $this->assertEquals(
            __('tournaments::messages.stat_type.float'),
            $options['float']
        );
        $this->assertEquals(
            __('tournaments::messages.stat_type.boolean'),
            $options['boolean']
        );
    }
}
