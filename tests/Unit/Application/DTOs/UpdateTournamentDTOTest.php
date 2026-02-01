<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs;

use Modules\Tournaments\Application\DTOs\UpdateTournamentDTO;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use PHPUnit\Framework\TestCase;

final class UpdateTournamentDTOTest extends TestCase
{
    public function test_can_create_dto_with_tournament_id_only(): void
    {
        $dto = new UpdateTournamentDTO(
            tournamentId: 'tournament-123',
        );

        $this->assertEquals('tournament-123', $dto->tournamentId);
        $this->assertNull($dto->name);
        $this->assertNull($dto->description);
        $this->assertNull($dto->maxRounds);
        $this->assertNull($dto->maxParticipants);
        $this->assertNull($dto->minParticipants);
        $this->assertNull($dto->scoreWeights);
        $this->assertNull($dto->tiebreakers);
        $this->assertNull($dto->allowGuests);
        $this->assertNull($dto->allowedRoles);
        $this->assertNull($dto->resultReporting);
        $this->assertNull($dto->requiresCheckIn);
        $this->assertNull($dto->checkInStartsBefore);
        $this->assertNull($dto->registrationOpensAt);
        $this->assertNull($dto->registrationClosesAt);
    }

    public function test_can_create_dto_with_partial_update(): void
    {
        $dto = new UpdateTournamentDTO(
            tournamentId: 'tournament-456',
            name: 'Updated Name',
            maxParticipants: 48,
        );

        $this->assertEquals('tournament-456', $dto->tournamentId);
        $this->assertEquals('Updated Name', $dto->name);
        $this->assertEquals(48, $dto->maxParticipants);
        $this->assertNull($dto->description);
        $this->assertNull($dto->maxRounds);
    }

    public function test_can_create_dto_with_all_fields(): void
    {
        $scoreWeights = [
            ['name' => 'Win', 'key' => 'win', 'points' => 3.0],
        ];
        $registrationOpens = new \DateTimeImmutable('2024-01-15 08:00:00');
        $registrationCloses = new \DateTimeImmutable('2024-01-25 20:00:00');

        $dto = new UpdateTournamentDTO(
            tournamentId: 'tournament-789',
            name: 'Full Update Tournament',
            description: 'Updated description',
            maxRounds: 7,
            maxParticipants: 128,
            minParticipants: 32,
            scoreWeights: $scoreWeights,
            tiebreakers: [Tiebreaker::Progressive, Tiebreaker::HeadToHead],
            allowGuests: true,
            allowedRoles: ['member', 'guest'],
            resultReporting: ResultReporting::PlayersWithConfirmation,
            requiresCheckIn: true,
            checkInStartsBefore: 45,
            registrationOpensAt: $registrationOpens,
            registrationClosesAt: $registrationCloses,
        );

        $this->assertEquals('tournament-789', $dto->tournamentId);
        $this->assertEquals('Full Update Tournament', $dto->name);
        $this->assertEquals('Updated description', $dto->description);
        $this->assertEquals(7, $dto->maxRounds);
        $this->assertEquals(128, $dto->maxParticipants);
        $this->assertEquals(32, $dto->minParticipants);
        $this->assertEquals($scoreWeights, $dto->scoreWeights);
        $this->assertEquals([Tiebreaker::Progressive, Tiebreaker::HeadToHead], $dto->tiebreakers);
        $this->assertTrue($dto->allowGuests);
        $this->assertEquals(['member', 'guest'], $dto->allowedRoles);
        $this->assertEquals(ResultReporting::PlayersWithConfirmation, $dto->resultReporting);
        $this->assertTrue($dto->requiresCheckIn);
        $this->assertEquals(45, $dto->checkInStartsBefore);
        $this->assertEquals($registrationOpens, $dto->registrationOpensAt);
        $this->assertEquals($registrationCloses, $dto->registrationClosesAt);
    }

    public function test_from_array_creates_dto(): void
    {
        $data = [
            'tournament_id' => 'tournament-aaa',
            'name' => 'Array Update',
            'max_rounds' => 5,
            'tiebreakers' => ['buchholz'],
            'result_reporting' => 'admin_only',
        ];

        $dto = UpdateTournamentDTO::fromArray($data);

        $this->assertEquals('tournament-aaa', $dto->tournamentId);
        $this->assertEquals('Array Update', $dto->name);
        $this->assertEquals(5, $dto->maxRounds);
        $this->assertEquals([Tiebreaker::Buchholz], $dto->tiebreakers);
        $this->assertEquals(ResultReporting::AdminOnly, $dto->resultReporting);
    }

    public function test_to_array_returns_only_set_values(): void
    {
        $dto = new UpdateTournamentDTO(
            tournamentId: 'tournament-bbb',
            name: 'Partial Update',
            maxParticipants: 24,
        );

        $array = $dto->toArray();

        $this->assertEquals('tournament-bbb', $array['tournament_id']);
        $this->assertEquals('Partial Update', $array['name']);
        $this->assertEquals(24, $array['max_participants']);
        $this->assertArrayNotHasKey('description', $array);
        $this->assertArrayNotHasKey('max_rounds', $array);
        $this->assertArrayNotHasKey('tiebreakers', $array);
    }

    public function test_to_array_includes_all_set_values(): void
    {
        $dto = new UpdateTournamentDTO(
            tournamentId: 'tournament-ccc',
            name: 'Full Array',
            description: 'Test description',
            maxRounds: 4,
            scoreWeights: [['name' => 'Win', 'key' => 'win', 'points' => 3.0]],
            tiebreakers: [Tiebreaker::Buchholz],
            allowGuests: false,
            resultReporting: ResultReporting::PlayersTrusted,
        );

        $array = $dto->toArray();

        $this->assertArrayHasKey('tournament_id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('max_rounds', $array);
        $this->assertArrayHasKey('score_weights', $array);
        $this->assertArrayHasKey('tiebreakers', $array);
        $this->assertArrayHasKey('allow_guests', $array);
        $this->assertArrayHasKey('result_reporting', $array);
        $this->assertEquals('players_trusted', $array['result_reporting']);
    }
}
