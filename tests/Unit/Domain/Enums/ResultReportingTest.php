<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\ResultReporting;
use PHPUnit\Framework\TestCase;

final class ResultReportingTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = ResultReporting::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(ResultReporting::AdminOnly, $cases);
        $this->assertContains(ResultReporting::PlayersWithConfirmation, $cases);
        $this->assertContains(ResultReporting::PlayersTrusted, $cases);
    }

    public function test_allows_player_reporting_returns_correct_values(): void
    {
        $this->assertFalse(ResultReporting::AdminOnly->allowsPlayerReporting());
        $this->assertTrue(ResultReporting::PlayersWithConfirmation->allowsPlayerReporting());
        $this->assertTrue(ResultReporting::PlayersTrusted->allowsPlayerReporting());
    }

    public function test_requires_confirmation_returns_correct_values(): void
    {
        $this->assertFalse(ResultReporting::AdminOnly->requiresConfirmation());
        $this->assertTrue(ResultReporting::PlayersWithConfirmation->requiresConfirmation());
        $this->assertFalse(ResultReporting::PlayersTrusted->requiresConfirmation());
    }

    public function test_values_returns_string_values(): void
    {
        $values = ResultReporting::values();

        $this->assertContains('admin_only', $values);
        $this->assertContains('players_with_confirmation', $values);
        $this->assertContains('players_trusted', $values);
    }

    public function test_all_reporting_modes_have_values(): void
    {
        $this->assertEquals('admin_only', ResultReporting::AdminOnly->value);
        $this->assertEquals('players_with_confirmation', ResultReporting::PlayersWithConfirmation->value);
        $this->assertEquals('players_trusted', ResultReporting::PlayersTrusted->value);
    }
}
