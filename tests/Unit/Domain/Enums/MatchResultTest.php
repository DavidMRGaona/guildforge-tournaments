<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;

final class MatchResultTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = MatchResult::cases();

        $this->assertCount(6, $cases);
        $this->assertContains(MatchResult::PlayerOneWin, $cases);
        $this->assertContains(MatchResult::PlayerTwoWin, $cases);
        $this->assertContains(MatchResult::Draw, $cases);
        $this->assertContains(MatchResult::DoubleLoss, $cases);
        $this->assertContains(MatchResult::Bye, $cases);
        $this->assertContains(MatchResult::NotPlayed, $cases);
    }

    public function test_player_one_win_gives_points_to_player_one(): void
    {
        $this->assertEquals(1.0, MatchResult::PlayerOneWin->player1Points());
        $this->assertEquals(0.0, MatchResult::PlayerOneWin->player2Points());
    }

    public function test_player_two_win_gives_points_to_player_two(): void
    {
        $this->assertEquals(0.0, MatchResult::PlayerTwoWin->player1Points());
        $this->assertEquals(1.0, MatchResult::PlayerTwoWin->player2Points());
    }

    public function test_draw_gives_half_point_to_both(): void
    {
        $this->assertEquals(0.5, MatchResult::Draw->player1Points());
        $this->assertEquals(0.5, MatchResult::Draw->player2Points());
    }

    public function test_double_loss_gives_no_points_to_either(): void
    {
        $this->assertEquals(0.0, MatchResult::DoubleLoss->player1Points());
        $this->assertEquals(0.0, MatchResult::DoubleLoss->player2Points());
    }

    public function test_bye_gives_full_point_to_player_one(): void
    {
        $this->assertEquals(1.0, MatchResult::Bye->player1Points());
        $this->assertEquals(0.0, MatchResult::Bye->player2Points());
    }

    public function test_not_played_gives_no_points(): void
    {
        $this->assertEquals(0.0, MatchResult::NotPlayed->player1Points());
        $this->assertEquals(0.0, MatchResult::NotPlayed->player2Points());
    }

    public function test_is_completed_returns_true_for_completed_results(): void
    {
        $this->assertTrue(MatchResult::PlayerOneWin->isCompleted());
        $this->assertTrue(MatchResult::PlayerTwoWin->isCompleted());
        $this->assertTrue(MatchResult::Draw->isCompleted());
        $this->assertTrue(MatchResult::DoubleLoss->isCompleted());
        $this->assertTrue(MatchResult::Bye->isCompleted());
        $this->assertFalse(MatchResult::NotPlayed->isCompleted());
    }

    public function test_is_bye_returns_true_only_for_bye(): void
    {
        $this->assertTrue(MatchResult::Bye->isBye());
        $this->assertFalse(MatchResult::PlayerOneWin->isBye());
        $this->assertFalse(MatchResult::PlayerTwoWin->isBye());
        $this->assertFalse(MatchResult::Draw->isBye());
        $this->assertFalse(MatchResult::DoubleLoss->isBye());
        $this->assertFalse(MatchResult::NotPlayed->isBye());
    }

    public function test_color_returns_appropriate_colors(): void
    {
        $this->assertEquals('success', MatchResult::PlayerOneWin->color());
        $this->assertEquals('success', MatchResult::PlayerTwoWin->color());
        $this->assertEquals('warning', MatchResult::Draw->color());
        $this->assertEquals('danger', MatchResult::DoubleLoss->color());
        $this->assertEquals('info', MatchResult::Bye->color());
        $this->assertEquals('gray', MatchResult::NotPlayed->color());
    }
}
