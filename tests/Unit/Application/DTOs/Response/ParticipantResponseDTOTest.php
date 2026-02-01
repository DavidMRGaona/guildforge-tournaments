<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use PHPUnit\Framework\TestCase;

final class ParticipantResponseDTOTest extends TestCase
{
    public function test_can_create_dto_for_registered_user(): void
    {
        $registeredAt = new DateTimeImmutable('2024-01-10 14:30:00');

        $dto = new ParticipantResponseDTO(
            id: 'participant-123',
            tournamentId: 'tournament-456',
            userId: 'user-789',
            userName: 'John Doe',
            userEmail: 'john@example.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::Registered,
            seed: 5,
            hasReceivedBye: false,
            registeredAt: $registeredAt,
            checkedInAt: null,
        );

        $this->assertEquals('participant-123', $dto->id);
        $this->assertEquals('tournament-456', $dto->tournamentId);
        $this->assertEquals('user-789', $dto->userId);
        $this->assertEquals('John Doe', $dto->userName);
        $this->assertEquals('john@example.com', $dto->userEmail);
        $this->assertNull($dto->guestName);
        $this->assertNull($dto->guestEmail);
        $this->assertEquals(ParticipantStatus::Registered, $dto->status);
        $this->assertEquals(5, $dto->seed);
        $this->assertFalse($dto->hasReceivedBye);
        $this->assertEquals($registeredAt, $dto->registeredAt);
        $this->assertNull($dto->checkedInAt);
    }

    public function test_can_create_dto_for_guest(): void
    {
        $registeredAt = new DateTimeImmutable('2024-01-11 09:00:00');

        $dto = new ParticipantResponseDTO(
            id: 'participant-guest',
            tournamentId: 'tournament-abc',
            userId: null,
            userName: null,
            userEmail: null,
            guestName: 'Guest Player',
            guestEmail: 'guest@example.com',
            status: ParticipantStatus::Confirmed,
            seed: null,
            hasReceivedBye: true,
            registeredAt: $registeredAt,
            checkedInAt: null,
        );

        $this->assertEquals('participant-guest', $dto->id);
        $this->assertNull($dto->userId);
        $this->assertNull($dto->userName);
        $this->assertEquals('Guest Player', $dto->guestName);
        $this->assertEquals('guest@example.com', $dto->guestEmail);
        $this->assertTrue($dto->hasReceivedBye);
    }

    public function test_is_guest_returns_true_for_guest_participant(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'guest-test',
            tournamentId: 'tournament',
            userId: null,
            userName: null,
            userEmail: null,
            guestName: 'Guest',
            guestEmail: 'guest@test.com',
            status: ParticipantStatus::Registered,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: null,
        );

        $this->assertTrue($dto->isGuest());
    }

    public function test_is_guest_returns_false_for_user_participant(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'user-test',
            tournamentId: 'tournament',
            userId: 'user-id',
            userName: 'User Name',
            userEmail: 'user@test.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::Registered,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: null,
        );

        $this->assertFalse($dto->isGuest());
    }

    public function test_display_name_returns_user_name_for_user(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'display-user',
            tournamentId: 'tournament',
            userId: 'user-id',
            userName: 'User Name',
            userEmail: 'user@test.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::Registered,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: null,
        );

        $this->assertEquals('User Name', $dto->displayName());
    }

    public function test_display_name_returns_guest_name_for_guest(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'display-guest',
            tournamentId: 'tournament',
            userId: null,
            userName: null,
            userEmail: null,
            guestName: 'Guest Name',
            guestEmail: 'guest@test.com',
            status: ParticipantStatus::Registered,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: null,
        );

        $this->assertEquals('Guest Name', $dto->displayName());
    }

    public function test_is_checked_in_returns_true_when_checked_in_at_is_set(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'checked-in',
            tournamentId: 'tournament',
            userId: 'user-id',
            userName: 'User',
            userEmail: 'user@test.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::CheckedIn,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: new DateTimeImmutable(),
        );

        $this->assertTrue($dto->isCheckedIn());
    }

    public function test_is_checked_in_returns_false_when_checked_in_at_is_null(): void
    {
        $dto = new ParticipantResponseDTO(
            id: 'not-checked-in',
            tournamentId: 'tournament',
            userId: 'user-id',
            userName: 'User',
            userEmail: 'user@test.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::Registered,
            seed: null,
            hasReceivedBye: false,
            registeredAt: new DateTimeImmutable(),
            checkedInAt: null,
        );

        $this->assertFalse($dto->isCheckedIn());
    }

    public function test_dto_properties_are_accessible(): void
    {
        // Note: toArray() calls label() on enums which requires Laravel's translator.
        // This test verifies DTO properties without triggering the translator.
        $registeredAt = new DateTimeImmutable('2024-01-15 12:00:00');
        $checkedInAt = new DateTimeImmutable('2024-01-20 09:30:00');

        $dto = new ParticipantResponseDTO(
            id: 'array-test',
            tournamentId: 'tournament-array',
            userId: 'user-array',
            userName: 'Array User',
            userEmail: 'array@test.com',
            guestName: null,
            guestEmail: null,
            status: ParticipantStatus::CheckedIn,
            seed: 3,
            hasReceivedBye: true,
            registeredAt: $registeredAt,
            checkedInAt: $checkedInAt,
        );

        $this->assertEquals('array-test', $dto->id);
        $this->assertEquals('tournament-array', $dto->tournamentId);
        $this->assertEquals('user-array', $dto->userId);
        $this->assertEquals('Array User', $dto->userName);
        $this->assertEquals('array@test.com', $dto->userEmail);
        $this->assertNull($dto->guestName);
        $this->assertNull($dto->guestEmail);
        $this->assertEquals(ParticipantStatus::CheckedIn, $dto->status);
        $this->assertEquals('checked_in', $dto->status->value);
        $this->assertEquals(3, $dto->seed);
        $this->assertTrue($dto->hasReceivedBye);
        $this->assertEquals($registeredAt, $dto->registeredAt);
        $this->assertEquals($checkedInAt, $dto->checkedInAt);
        $this->assertEquals('Array User', $dto->displayName());
        $this->assertFalse($dto->isGuest());
        $this->assertTrue($dto->isCheckedIn());
    }
}
