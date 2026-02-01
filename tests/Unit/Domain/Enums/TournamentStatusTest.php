<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\TournamentStatus;
use PHPUnit\Framework\TestCase;

final class TournamentStatusTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = TournamentStatus::cases();

        $this->assertCount(6, $cases);
        $this->assertContains(TournamentStatus::Draft, $cases);
        $this->assertContains(TournamentStatus::RegistrationOpen, $cases);
        $this->assertContains(TournamentStatus::RegistrationClosed, $cases);
        $this->assertContains(TournamentStatus::InProgress, $cases);
        $this->assertContains(TournamentStatus::Finished, $cases);
        $this->assertContains(TournamentStatus::Cancelled, $cases);
    }

    public function test_draft_can_transition_to_registration_open(): void
    {
        $this->assertTrue(TournamentStatus::Draft->canTransitionTo(TournamentStatus::RegistrationOpen));
    }

    public function test_draft_can_transition_to_cancelled(): void
    {
        $this->assertTrue(TournamentStatus::Draft->canTransitionTo(TournamentStatus::Cancelled));
    }

    public function test_draft_cannot_transition_to_in_progress(): void
    {
        $this->assertFalse(TournamentStatus::Draft->canTransitionTo(TournamentStatus::InProgress));
    }

    public function test_registration_open_can_transition_to_registration_closed(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationOpen->canTransitionTo(TournamentStatus::RegistrationClosed));
    }

    public function test_registration_open_can_transition_to_cancelled(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationOpen->canTransitionTo(TournamentStatus::Cancelled));
    }

    public function test_registration_closed_can_transition_to_in_progress(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationClosed->canTransitionTo(TournamentStatus::InProgress));
    }

    public function test_registration_closed_can_transition_to_cancelled(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationClosed->canTransitionTo(TournamentStatus::Cancelled));
    }

    public function test_in_progress_can_transition_to_finished(): void
    {
        $this->assertTrue(TournamentStatus::InProgress->canTransitionTo(TournamentStatus::Finished));
    }

    public function test_in_progress_can_transition_to_cancelled(): void
    {
        $this->assertTrue(TournamentStatus::InProgress->canTransitionTo(TournamentStatus::Cancelled));
    }

    public function test_finished_cannot_transition_to_any_state(): void
    {
        $this->assertFalse(TournamentStatus::Finished->canTransitionTo(TournamentStatus::Draft));
        $this->assertFalse(TournamentStatus::Finished->canTransitionTo(TournamentStatus::InProgress));
        $this->assertFalse(TournamentStatus::Finished->canTransitionTo(TournamentStatus::Cancelled));
    }

    public function test_cancelled_cannot_transition_to_any_state(): void
    {
        $this->assertFalse(TournamentStatus::Cancelled->canTransitionTo(TournamentStatus::Draft));
        $this->assertFalse(TournamentStatus::Cancelled->canTransitionTo(TournamentStatus::InProgress));
        $this->assertFalse(TournamentStatus::Cancelled->canTransitionTo(TournamentStatus::Finished));
    }

    public function test_is_registration_open_returns_true_only_for_registration_open(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationOpen->isRegistrationOpen());
        $this->assertFalse(TournamentStatus::Draft->isRegistrationOpen());
        $this->assertFalse(TournamentStatus::RegistrationClosed->isRegistrationOpen());
        $this->assertFalse(TournamentStatus::InProgress->isRegistrationOpen());
        $this->assertFalse(TournamentStatus::Finished->isRegistrationOpen());
        $this->assertFalse(TournamentStatus::Cancelled->isRegistrationOpen());
    }

    public function test_is_active_returns_true_for_active_states(): void
    {
        $this->assertTrue(TournamentStatus::RegistrationOpen->isActive());
        $this->assertTrue(TournamentStatus::RegistrationClosed->isActive());
        $this->assertTrue(TournamentStatus::InProgress->isActive());
        $this->assertFalse(TournamentStatus::Draft->isActive());
        $this->assertFalse(TournamentStatus::Finished->isActive());
        $this->assertFalse(TournamentStatus::Cancelled->isActive());
    }

    public function test_is_final_returns_true_for_final_states(): void
    {
        $this->assertTrue(TournamentStatus::Finished->isFinal());
        $this->assertTrue(TournamentStatus::Cancelled->isFinal());
        $this->assertFalse(TournamentStatus::Draft->isFinal());
        $this->assertFalse(TournamentStatus::RegistrationOpen->isFinal());
        $this->assertFalse(TournamentStatus::RegistrationClosed->isFinal());
        $this->assertFalse(TournamentStatus::InProgress->isFinal());
    }

    public function test_color_returns_appropriate_colors(): void
    {
        $this->assertEquals('gray', TournamentStatus::Draft->color());
        $this->assertEquals('success', TournamentStatus::RegistrationOpen->color());
        $this->assertEquals('warning', TournamentStatus::RegistrationClosed->color());
        $this->assertEquals('info', TournamentStatus::InProgress->color());
        $this->assertEquals('primary', TournamentStatus::Finished->color());
        $this->assertEquals('danger', TournamentStatus::Cancelled->color());
    }

    public function test_values_returns_string_values(): void
    {
        $values = TournamentStatus::values();

        $this->assertContains('draft', $values);
        $this->assertContains('registration_open', $values);
        $this->assertContains('registration_closed', $values);
        $this->assertContains('in_progress', $values);
        $this->assertContains('finished', $values);
        $this->assertContains('cancelled', $values);
    }
}
