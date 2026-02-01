<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use PHPUnit\Framework\TestCase;

final class ParticipantTest extends TestCase
{
    private function createParticipant(
        ParticipantStatus $status = ParticipantStatus::Registered,
        ?string $userId = null,
        ?string $guestName = null,
        ?string $guestEmail = null,
    ): Participant {
        return new Participant(
            id: ParticipantId::generate(),
            tournamentId: '550e8400-e29b-41d4-a716-446655440000',
            status: $status,
            userId: $userId ?? '660e8400-e29b-41d4-a716-446655440001',
            guestName: $guestName,
            guestEmail: $guestEmail,
        );
    }

    private function createGuestParticipant(ParticipantStatus $status = ParticipantStatus::Registered): Participant
    {
        return new Participant(
            id: ParticipantId::generate(),
            tournamentId: '550e8400-e29b-41d4-a716-446655440000',
            status: $status,
            userId: null,
            guestName: 'John Doe',
            guestEmail: 'john@example.com',
        );
    }

    public function test_it_creates_user_participant(): void
    {
        $participant = $this->createParticipant();

        $this->assertInstanceOf(ParticipantId::class, $participant->id());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $participant->tournamentId());
        $this->assertEquals('660e8400-e29b-41d4-a716-446655440001', $participant->userId());
        $this->assertNull($participant->guestName());
        $this->assertNull($participant->guestEmail());
        $this->assertEquals(ParticipantStatus::Registered, $participant->status());
        $this->assertFalse($participant->isGuest());
    }

    public function test_it_creates_guest_participant(): void
    {
        $participant = $this->createGuestParticipant();

        $this->assertNull($participant->userId());
        $this->assertEquals('John Doe', $participant->guestName());
        $this->assertEquals('john@example.com', $participant->guestEmail());
        $this->assertTrue($participant->isGuest());
    }

    public function test_display_name_returns_guest_name_for_guest(): void
    {
        $participant = $this->createGuestParticipant();

        $this->assertEquals('John Doe', $participant->displayName());
    }

    public function test_display_name_returns_null_for_user_participant(): void
    {
        $participant = $this->createParticipant();

        // For user participants, displayName returns null (name comes from User model)
        $this->assertNull($participant->displayName());
    }

    public function test_registered_can_confirm(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Registered);

        $participant->confirm();

        $this->assertEquals(ParticipantStatus::Confirmed, $participant->status());
    }

    public function test_confirmed_can_check_in(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Confirmed);

        $participant->checkIn();

        $this->assertEquals(ParticipantStatus::CheckedIn, $participant->status());
        $this->assertInstanceOf(DateTimeImmutable::class, $participant->checkedInAt());
    }

    public function test_registered_can_withdraw(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Registered);

        $participant->withdraw();

        $this->assertEquals(ParticipantStatus::Withdrawn, $participant->status());
    }

    public function test_confirmed_can_withdraw(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Confirmed);

        $participant->withdraw();

        $this->assertEquals(ParticipantStatus::Withdrawn, $participant->status());
    }

    public function test_checked_in_can_withdraw(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::CheckedIn);

        $participant->withdraw();

        $this->assertEquals(ParticipantStatus::Withdrawn, $participant->status());
    }

    public function test_confirmed_can_be_disqualified(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Confirmed);

        $participant->disqualify();

        $this->assertEquals(ParticipantStatus::Disqualified, $participant->status());
    }

    public function test_checked_in_can_be_disqualified(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::CheckedIn);

        $participant->disqualify();

        $this->assertEquals(ParticipantStatus::Disqualified, $participant->status());
    }

    public function test_withdrawn_cannot_transition(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Withdrawn);

        $this->expectException(InvalidStateTransitionException::class);

        $participant->confirm();
    }

    public function test_disqualified_cannot_transition(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Disqualified);

        $this->expectException(InvalidStateTransitionException::class);

        $participant->withdraw();
    }

    public function test_registered_cannot_check_in_directly(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Registered);

        $this->expectException(InvalidStateTransitionException::class);

        $participant->checkIn();
    }

    public function test_has_received_bye_default_false(): void
    {
        $participant = $this->createParticipant();

        $this->assertFalse($participant->hasReceivedBye());
    }

    public function test_can_mark_bye_received(): void
    {
        $participant = $this->createParticipant();

        $participant->markByeReceived();

        $this->assertTrue($participant->hasReceivedBye());
    }

    public function test_can_set_seed(): void
    {
        $participant = $this->createParticipant();

        $participant->setSeed(1);

        $this->assertEquals(1, $participant->seed());
    }

    public function test_is_active_returns_correct_values(): void
    {
        $registered = $this->createParticipant(ParticipantStatus::Registered);
        $confirmed = $this->createParticipant(ParticipantStatus::Confirmed);
        $checkedIn = $this->createParticipant(ParticipantStatus::CheckedIn);
        $withdrawn = $this->createParticipant(ParticipantStatus::Withdrawn);
        $disqualified = $this->createParticipant(ParticipantStatus::Disqualified);

        $this->assertTrue($registered->isActive());
        $this->assertTrue($confirmed->isActive());
        $this->assertTrue($checkedIn->isActive());
        $this->assertFalse($withdrawn->isActive());
        $this->assertFalse($disqualified->isActive());
    }

    public function test_can_play_returns_correct_values(): void
    {
        $registered = $this->createParticipant(ParticipantStatus::Registered);
        $confirmed = $this->createParticipant(ParticipantStatus::Confirmed);
        $checkedIn = $this->createParticipant(ParticipantStatus::CheckedIn);
        $withdrawn = $this->createParticipant(ParticipantStatus::Withdrawn);
        $disqualified = $this->createParticipant(ParticipantStatus::Disqualified);

        $this->assertFalse($registered->canPlay());
        $this->assertTrue($confirmed->canPlay());
        $this->assertTrue($checkedIn->canPlay());
        $this->assertFalse($withdrawn->canPlay());
        $this->assertFalse($disqualified->canPlay());
    }

    public function test_withdrawn_can_reactivate(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Withdrawn);

        $participant->reactivate();

        $this->assertEquals(ParticipantStatus::Registered, $participant->status());
    }

    public function test_reactivate_clears_checked_in_at(): void
    {
        $participant = new Participant(
            id: ParticipantId::generate(),
            tournamentId: '550e8400-e29b-41d4-a716-446655440000',
            status: ParticipantStatus::Withdrawn,
            userId: '660e8400-e29b-41d4-a716-446655440001',
            checkedInAt: new DateTimeImmutable,
        );

        $participant->reactivate();

        $this->assertNull($participant->checkedInAt());
    }

    public function test_registered_cannot_reactivate(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Registered);

        $this->expectException(InvalidStateTransitionException::class);

        $participant->reactivate();
    }

    public function test_disqualified_cannot_reactivate(): void
    {
        $participant = $this->createParticipant(ParticipantStatus::Disqualified);

        $this->expectException(InvalidStateTransitionException::class);

        $participant->reactivate();
    }
}
