<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\SortDirection;
use Tests\TestCase;

final class SortDirectionTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = SortDirection::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(SortDirection::Ascending, $cases);
        $this->assertContains(SortDirection::Descending, $cases);
    }

    public function test_ascending_has_correct_value(): void
    {
        $this->assertEquals('asc', SortDirection::Ascending->value);
    }

    public function test_descending_has_correct_value(): void
    {
        $this->assertEquals('desc', SortDirection::Descending->value);
    }

    public function test_values_returns_string_values(): void
    {
        $values = SortDirection::values();

        $this->assertCount(2, $values);
        $this->assertContains('asc', $values);
        $this->assertContains('desc', $values);
    }

    public function test_label_returns_translated_label(): void
    {
        $this->assertEquals(
            __('tournaments::messages.sort_direction.ascending'),
            SortDirection::Ascending->label()
        );
        $this->assertEquals(
            __('tournaments::messages.sort_direction.descending'),
            SortDirection::Descending->label()
        );
    }

    public function test_options_returns_value_label_pairs(): void
    {
        $options = SortDirection::options();

        $this->assertIsArray($options);
        $this->assertCount(2, $options);
        $this->assertArrayHasKey('asc', $options);
        $this->assertArrayHasKey('desc', $options);

        $this->assertEquals(
            __('tournaments::messages.sort_direction.ascending'),
            $options['asc']
        );
        $this->assertEquals(
            __('tournaments::messages.sort_direction.descending'),
            $options['desc']
        );
    }
}
