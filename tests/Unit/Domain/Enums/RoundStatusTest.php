<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\RoundStatus;
use PHPUnit\Framework\TestCase;

final class RoundStatusTest extends TestCase
{
    public function test_pending_can_transition_to_in_progress(): void
    {
        $this->assertTrue(RoundStatus::Pending->canTransitionTo(RoundStatus::InProgress));
    }

    public function test_pending_cannot_transition_to_finished(): void
    {
        $this->assertFalse(RoundStatus::Pending->canTransitionTo(RoundStatus::Finished));
    }

    public function test_in_progress_can_transition_to_finished(): void
    {
        $this->assertTrue(RoundStatus::InProgress->canTransitionTo(RoundStatus::Finished));
    }

    public function test_in_progress_cannot_transition_to_pending(): void
    {
        $this->assertFalse(RoundStatus::InProgress->canTransitionTo(RoundStatus::Pending));
    }

    public function test_finished_cannot_transition_to_any_state(): void
    {
        $this->assertFalse(RoundStatus::Finished->canTransitionTo(RoundStatus::Pending));
        $this->assertFalse(RoundStatus::Finished->canTransitionTo(RoundStatus::InProgress));
    }

    public function test_is_active_returns_true_only_for_in_progress(): void
    {
        $this->assertTrue(RoundStatus::InProgress->isActive());
        $this->assertFalse(RoundStatus::Pending->isActive());
        $this->assertFalse(RoundStatus::Finished->isActive());
    }

    public function test_color_returns_appropriate_colors(): void
    {
        $this->assertEquals('gray', RoundStatus::Pending->color());
        $this->assertEquals('info', RoundStatus::InProgress->color());
        $this->assertEquals('success', RoundStatus::Finished->color());
    }
}
