<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
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

    private function awaiting(GuestConfirmationMode $mode): Participant
    {
        $participant = $this->createGuestParticipant();
        $participant->startEmailConfirmation(
            'token-1',
            $mode,
            new DateTimeImmutable('2030-01-01 12:00:00'),
            new DateTimeImmutable('2030-01-01 10:00:00'),
        );

        return $participant;
    }

    public function test_a_guest_awaiting_confirmation_in_wait_mode_holds_no_seat(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Wait);

        $this->assertTrue($participant->isAwaitingEmailConfirmation());
        $this->assertTrue($participant->isActive());
        $this->assertFalse($participant->holdsSeat());
    }

    public function test_a_guest_awaiting_confirmation_in_hold_mode_holds_a_seat(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);

        $this->assertTrue($participant->isAwaitingEmailConfirmation());
        $this->assertTrue($participant->holdsSeat());
        $this->assertSame('token-1', $participant->confirmationToken());
        $this->assertSame(GuestConfirmationMode::Hold, $participant->confirmationMode());
        $this->assertNull($participant->emailConfirmedAt());
        $this->assertEquals(new DateTimeImmutable('2030-01-01 10:00:00'), $participant->confirmationSentAt());
    }

    public function test_a_user_is_never_awaiting_confirmation(): void
    {
        $participant = $this->createParticipant();

        $this->assertFalse($participant->isAwaitingEmailConfirmation());
        $this->assertTrue($participant->holdsSeat());
    }

    public function test_mark_email_confirmed_is_idempotent(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $at = new DateTimeImmutable('2030-01-01 11:00:00');

        $this->assertTrue($participant->markEmailConfirmed($at));
        $this->assertFalse($participant->markEmailConfirmed(new DateTimeImmutable('2030-01-01 11:30:00')));

        $this->assertEquals($at, $participant->emailConfirmedAt());
        $this->assertSame('token-1', $participant->confirmationToken());
        $this->assertNull($participant->confirmationExpiresAt());
        $this->assertFalse($participant->isAwaitingEmailConfirmation());
    }

    public function test_mark_confirmation_sent_records_the_time(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $at = new DateTimeImmutable('2030-01-01 10:30:00');

        $participant->markConfirmationSent($at);

        $this->assertEquals($at, $participant->confirmationSentAt());
    }

    public function test_start_email_confirmation_clears_a_previous_confirmation(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $participant->markEmailConfirmed(new DateTimeImmutable('2030-01-01 11:00:00'));

        $participant->startEmailConfirmation('token-2', GuestConfirmationMode::Wait, null, new DateTimeImmutable('2030-01-02 10:00:00'));

        $this->assertNull($participant->emailConfirmedAt());
        $this->assertSame('token-2', $participant->confirmationToken());
        $this->assertNull($participant->confirmationExpiresAt());
    }

    public function test_expire_moves_to_expired_and_clears_the_deadline(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);

        $participant->expire();

        $this->assertSame(ParticipantStatus::Expired, $participant->status());
        $this->assertNull($participant->confirmationExpiresAt());
        $this->assertFalse($participant->isActive());
    }

    public function test_an_expired_registration_can_be_reactivated(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $participant->expire();

        $participant->reactivate();

        $this->assertSame(ParticipantStatus::Registered, $participant->status());
    }

    public function test_claim_for_user_turns_the_guest_row_into_the_users(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $participant->setCancellationToken('cancel-1');
        $now = new DateTimeImmutable('2030-01-01 11:00:00');

        $participant->claimForUser('770e8400-e29b-41d4-a716-446655440002', $now);

        $this->assertFalse($participant->isGuest());
        $this->assertSame('770e8400-e29b-41d4-a716-446655440002', $participant->userId());
        $this->assertNull($participant->guestName());
        $this->assertNull($participant->guestEmail());
        $this->assertNull($participant->cancellationToken());
        $this->assertNull($participant->confirmationToken());
        $this->assertNull($participant->confirmationExpiresAt());
        $this->assertEquals($now, $participant->emailConfirmedAt());
    }

    public function test_claim_for_user_keeps_an_earlier_confirmation(): void
    {
        $participant = $this->awaiting(GuestConfirmationMode::Hold);
        $earlier = new DateTimeImmutable('2030-01-01 10:30:00');
        $participant->markEmailConfirmed($earlier);

        $participant->claimForUser('770e8400-e29b-41d4-a716-446655440002', new DateTimeImmutable('2030-01-01 11:00:00'));

        $this->assertEquals($earlier, $participant->emailConfirmedAt());
    }

    public function test_a_withdrawn_user_row_takes_over_an_active_guest_registration(): void
    {
        $guest = $this->awaiting(GuestConfirmationMode::Hold);
        $guest->confirm();
        $own = $this->createParticipant(ParticipantStatus::Withdrawn);
        $now = new DateTimeImmutable('2030-01-01 11:00:00');

        $own->takeOverGuestRegistration($guest, $now);

        $this->assertSame(ParticipantStatus::Confirmed, $own->status());
        $this->assertEquals($now, $own->emailConfirmedAt());
        $this->assertSame(GuestConfirmationMode::Hold, $own->confirmationMode());
        $this->assertEquals(new DateTimeImmutable('2030-01-01 10:00:00'), $own->confirmationSentAt());
        $this->assertNull($own->confirmationToken());
        $this->assertNull($own->confirmationExpiresAt());
        $this->assertNull($own->cancellationToken());
        $this->assertSame('660e8400-e29b-41d4-a716-446655440001', $own->userId());
    }

    public function test_only_a_withdrawn_user_row_takes_over_a_guest_registration(): void
    {
        $own = $this->createParticipant(ParticipantStatus::Registered);

        $this->expectException(InvalidStateTransitionException::class);

        $own->takeOverGuestRegistration($this->createGuestParticipant(), new DateTimeImmutable('2030-01-01 11:00:00'));
    }

    public function test_an_inactive_guest_registration_cannot_be_taken_over(): void
    {
        $own = $this->createParticipant(ParticipantStatus::Withdrawn);

        $this->expectException(InvalidStateTransitionException::class);

        $own->takeOverGuestRegistration($this->createGuestParticipant(ParticipantStatus::Withdrawn), new DateTimeImmutable('2030-01-01 11:00:00'));
    }

    public function test_handing_a_guest_registration_over_withdraws_it_without_links(): void
    {
        $guest = $this->awaiting(GuestConfirmationMode::Hold);
        $guest->setCancellationToken('cancel-1');

        $guest->handOverToAccount();

        $this->assertSame(ParticipantStatus::Withdrawn, $guest->status());
        $this->assertNull($guest->cancellationToken());
        $this->assertNull($guest->confirmationToken());
        $this->assertNull($guest->confirmationExpiresAt());
        $this->assertSame('john@example.com', $guest->guestEmail());
    }

    public function test_record_fingerprint(): void
    {
        $participant = $this->createGuestParticipant();

        $participant->recordFingerprint('abc');
        $this->assertSame('abc', $participant->fingerprint());

        $participant->recordFingerprint(null);
        $this->assertNull($participant->fingerprint());
    }

    public function test_rename_guest(): void
    {
        $participant = $this->createGuestParticipant(ParticipantStatus::Expired);

        $participant->renameGuest('Ana María');

        $this->assertSame('Ana María', $participant->guestName());
    }
}
