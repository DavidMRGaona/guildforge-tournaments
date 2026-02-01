<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs;

use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use PHPUnit\Framework\TestCase;

final class RegisterParticipantDTOTest extends TestCase
{
    public function test_can_create_dto_for_registered_user(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-123',
            userId: 'user-456',
        );

        $this->assertEquals('tournament-123', $dto->tournamentId);
        $this->assertEquals('user-456', $dto->userId);
        $this->assertNull($dto->guestName);
        $this->assertNull($dto->guestEmail);
        $this->assertNull($dto->seed);
    }

    public function test_can_create_dto_for_guest(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-789',
            userId: null,
            guestName: 'Guest Player',
            guestEmail: 'guest@example.com',
        );

        $this->assertEquals('tournament-789', $dto->tournamentId);
        $this->assertNull($dto->userId);
        $this->assertEquals('Guest Player', $dto->guestName);
        $this->assertEquals('guest@example.com', $dto->guestEmail);
    }

    public function test_can_create_dto_with_seed(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-abc',
            userId: 'user-xyz',
            seed: 5,
        );

        $this->assertEquals('tournament-abc', $dto->tournamentId);
        $this->assertEquals('user-xyz', $dto->userId);
        $this->assertEquals(5, $dto->seed);
    }

    public function test_is_guest_returns_true_for_guest_registration(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-111',
            userId: null,
            guestName: 'Guest Name',
        );

        $this->assertTrue($dto->isGuest());
    }

    public function test_is_guest_returns_false_for_user_registration(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-222',
            userId: 'user-333',
        );

        $this->assertFalse($dto->isGuest());
    }

    public function test_from_array_creates_user_registration_dto(): void
    {
        $data = [
            'tournament_id' => 'tournament-aaa',
            'user_id' => 'user-bbb',
            'seed' => 10,
        ];

        $dto = RegisterParticipantDTO::fromArray($data);

        $this->assertEquals('tournament-aaa', $dto->tournamentId);
        $this->assertEquals('user-bbb', $dto->userId);
        $this->assertEquals(10, $dto->seed);
        $this->assertNull($dto->guestName);
        $this->assertNull($dto->guestEmail);
    }

    public function test_from_array_creates_guest_registration_dto(): void
    {
        $data = [
            'tournament_id' => 'tournament-ccc',
            'guest_name' => 'External Player',
            'guest_email' => 'external@example.com',
        ];

        $dto = RegisterParticipantDTO::fromArray($data);

        $this->assertEquals('tournament-ccc', $dto->tournamentId);
        $this->assertNull($dto->userId);
        $this->assertEquals('External Player', $dto->guestName);
        $this->assertEquals('external@example.com', $dto->guestEmail);
    }

    public function test_to_array_returns_correct_structure_for_user(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-ddd',
            userId: 'user-eee',
            seed: 1,
        );

        $array = $dto->toArray();

        $this->assertEquals('tournament-ddd', $array['tournament_id']);
        $this->assertEquals('user-eee', $array['user_id']);
        $this->assertEquals(1, $array['seed']);
        $this->assertNull($array['guest_name']);
        $this->assertNull($array['guest_email']);
    }

    public function test_to_array_returns_correct_structure_for_guest(): void
    {
        $dto = new RegisterParticipantDTO(
            tournamentId: 'tournament-fff',
            userId: null,
            guestName: 'Guest User',
            guestEmail: 'guest.user@example.com',
        );

        $array = $dto->toArray();

        $this->assertEquals('tournament-fff', $array['tournament_id']);
        $this->assertNull($array['user_id']);
        $this->assertEquals('Guest User', $array['guest_name']);
        $this->assertEquals('guest.user@example.com', $array['guest_email']);
    }
}
