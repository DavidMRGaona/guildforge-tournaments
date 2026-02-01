<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use PHPUnit\Framework\TestCase;

final class ParticipantStatusTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = ParticipantStatus::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(ParticipantStatus::Registered, $cases);
        $this->assertContains(ParticipantStatus::Confirmed, $cases);
        $this->assertContains(ParticipantStatus::CheckedIn, $cases);
        $this->assertContains(ParticipantStatus::Withdrawn, $cases);
        $this->assertContains(ParticipantStatus::Disqualified, $cases);
    }

    public function test_registered_can_transition_to_confirmed(): void
    {
        $this->assertTrue(ParticipantStatus::Registered->canTransitionTo(ParticipantStatus::Confirmed));
    }

    public function test_registered_can_transition_to_withdrawn(): void
    {
        $this->assertTrue(ParticipantStatus::Registered->canTransitionTo(ParticipantStatus::Withdrawn));
    }

    public function test_confirmed_can_transition_to_checked_in(): void
    {
        $this->assertTrue(ParticipantStatus::Confirmed->canTransitionTo(ParticipantStatus::CheckedIn));
    }

    public function test_confirmed_can_transition_to_withdrawn(): void
    {
        $this->assertTrue(ParticipantStatus::Confirmed->canTransitionTo(ParticipantStatus::Withdrawn));
    }

    public function test_confirmed_can_transition_to_disqualified(): void
    {
        $this->assertTrue(ParticipantStatus::Confirmed->canTransitionTo(ParticipantStatus::Disqualified));
    }

    public function test_checked_in_can_transition_to_withdrawn(): void
    {
        $this->assertTrue(ParticipantStatus::CheckedIn->canTransitionTo(ParticipantStatus::Withdrawn));
    }

    public function test_checked_in_can_transition_to_disqualified(): void
    {
        $this->assertTrue(ParticipantStatus::CheckedIn->canTransitionTo(ParticipantStatus::Disqualified));
    }

    public function test_withdrawn_can_transition_to_registered(): void
    {
        $this->assertTrue(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::Registered));
    }

    public function test_withdrawn_cannot_transition_to_other_states(): void
    {
        $this->assertFalse(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::Confirmed));
        $this->assertFalse(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::CheckedIn));
        $this->assertFalse(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::Disqualified));
    }

    public function test_disqualified_cannot_transition_to_any_state(): void
    {
        $this->assertFalse(ParticipantStatus::Disqualified->canTransitionTo(ParticipantStatus::Registered));
        $this->assertFalse(ParticipantStatus::Disqualified->canTransitionTo(ParticipantStatus::Confirmed));
        $this->assertFalse(ParticipantStatus::Disqualified->canTransitionTo(ParticipantStatus::CheckedIn));
    }

    public function test_is_active_returns_true_for_active_states(): void
    {
        $this->assertTrue(ParticipantStatus::Registered->isActive());
        $this->assertTrue(ParticipantStatus::Confirmed->isActive());
        $this->assertTrue(ParticipantStatus::CheckedIn->isActive());
        $this->assertFalse(ParticipantStatus::Withdrawn->isActive());
        $this->assertFalse(ParticipantStatus::Disqualified->isActive());
    }

    public function test_is_final_returns_true_for_final_states(): void
    {
        $this->assertTrue(ParticipantStatus::Withdrawn->isFinal());
        $this->assertTrue(ParticipantStatus::Disqualified->isFinal());
        $this->assertFalse(ParticipantStatus::Registered->isFinal());
        $this->assertFalse(ParticipantStatus::Confirmed->isFinal());
        $this->assertFalse(ParticipantStatus::CheckedIn->isFinal());
    }

    public function test_can_play_returns_true_for_playable_states(): void
    {
        $this->assertTrue(ParticipantStatus::Confirmed->canPlay());
        $this->assertTrue(ParticipantStatus::CheckedIn->canPlay());
        $this->assertFalse(ParticipantStatus::Registered->canPlay());
        $this->assertFalse(ParticipantStatus::Withdrawn->canPlay());
        $this->assertFalse(ParticipantStatus::Disqualified->canPlay());
    }

    public function test_color_returns_appropriate_colors(): void
    {
        $this->assertEquals('warning', ParticipantStatus::Registered->color());
        $this->assertEquals('info', ParticipantStatus::Confirmed->color());
        $this->assertEquals('success', ParticipantStatus::CheckedIn->color());
        $this->assertEquals('gray', ParticipantStatus::Withdrawn->color());
        $this->assertEquals('danger', ParticipantStatus::Disqualified->color());
    }
}
