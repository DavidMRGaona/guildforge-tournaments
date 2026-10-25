<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Tests\TestCase;

final class ByeAssignmentTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = ByeAssignment::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(ByeAssignment::LowestRanked, $cases);
        $this->assertContains(ByeAssignment::Random, $cases);
        $this->assertContains(ByeAssignment::HighestRanked, $cases);
    }

    public function test_all_bye_assignments_have_correct_values(): void
    {
        $this->assertEquals('lowest_ranked', ByeAssignment::LowestRanked->value);
        $this->assertEquals('random', ByeAssignment::Random->value);
        $this->assertEquals('highest_ranked', ByeAssignment::HighestRanked->value);
    }

    public function test_values_returns_string_values(): void
    {
        $values = ByeAssignment::values();

        $this->assertCount(3, $values);
        $this->assertContains('lowest_ranked', $values);
        $this->assertContains('random', $values);
        $this->assertContains('highest_ranked', $values);
    }

    public function test_options_returns_label_value_pairs(): void
    {
        $options = ByeAssignment::options();

        $this->assertCount(3, $options);
        $this->assertArrayHasKey('lowest_ranked', $options);
        $this->assertArrayHasKey('random', $options);
        $this->assertArrayHasKey('highest_ranked', $options);
    }

    public function test_label_returns_translated_string_for_lowest_ranked(): void
    {
        $this->assertSame(__('tournaments::messages.bye_assignment.lowest_ranked'), ByeAssignment::LowestRanked->label());
    }

    public function test_label_returns_translated_string_for_random(): void
    {
        $this->assertSame(__('tournaments::messages.bye_assignment.random'), ByeAssignment::Random->label());
    }

    public function test_label_returns_translated_string_for_highest_ranked(): void
    {
        $this->assertSame(__('tournaments::messages.bye_assignment.highest_ranked'), ByeAssignment::HighestRanked->label());
    }
}
