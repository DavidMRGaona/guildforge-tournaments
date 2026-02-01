<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs;

use Modules\Tournaments\Application\DTOs\CreateTournamentDTO;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use PHPUnit\Framework\TestCase;

final class CreateTournamentDTOTest extends TestCase
{
    public function test_can_create_dto_with_required_fields(): void
    {
        $dto = new CreateTournamentDTO(
            eventId: 'event-123',
            name: 'Test Tournament',
        );

        $this->assertEquals('event-123', $dto->eventId);
        $this->assertEquals('Test Tournament', $dto->name);
        $this->assertNull($dto->description);
        $this->assertNull($dto->maxRounds);
        $this->assertNull($dto->maxParticipants);
        $this->assertNull($dto->minParticipants);
        $this->assertEquals([], $dto->scoreWeights);
        $this->assertEquals([], $dto->tiebreakers);
        $this->assertFalse($dto->allowGuests);
        $this->assertEquals([], $dto->allowedRoles);
        $this->assertEquals(ResultReporting::AdminOnly, $dto->resultReporting);
        $this->assertFalse($dto->requiresCheckIn);
        $this->assertNull($dto->checkInStartsBefore);
        $this->assertNull($dto->registrationOpensAt);
        $this->assertNull($dto->registrationClosesAt);
    }

    public function test_can_create_dto_with_all_fields(): void
    {
        $scoreWeights = [
            ['name' => 'Win', 'key' => 'win', 'points' => 3.0],
            ['name' => 'Draw', 'key' => 'draw', 'points' => 1.0],
        ];

        $registrationOpens = new \DateTimeImmutable('2024-01-01 10:00:00');
        $registrationCloses = new \DateTimeImmutable('2024-01-10 18:00:00');

        $dto = new CreateTournamentDTO(
            eventId: 'event-456',
            name: 'Championship 2024',
            description: 'Annual championship tournament',
            maxRounds: 5,
            maxParticipants: 32,
            minParticipants: 8,
            scoreWeights: $scoreWeights,
            tiebreakers: [Tiebreaker::Buchholz, Tiebreaker::Progressive],
            allowGuests: true,
            allowedRoles: ['member', 'premium'],
            resultReporting: ResultReporting::PlayersWithConfirmation,
            requiresCheckIn: true,
            checkInStartsBefore: 60,
            registrationOpensAt: $registrationOpens,
            registrationClosesAt: $registrationCloses,
        );

        $this->assertEquals('event-456', $dto->eventId);
        $this->assertEquals('Championship 2024', $dto->name);
        $this->assertEquals('Annual championship tournament', $dto->description);
        $this->assertEquals(5, $dto->maxRounds);
        $this->assertEquals(32, $dto->maxParticipants);
        $this->assertEquals(8, $dto->minParticipants);
        $this->assertEquals($scoreWeights, $dto->scoreWeights);
        $this->assertEquals([Tiebreaker::Buchholz, Tiebreaker::Progressive], $dto->tiebreakers);
        $this->assertTrue($dto->allowGuests);
        $this->assertEquals(['member', 'premium'], $dto->allowedRoles);
        $this->assertEquals(ResultReporting::PlayersWithConfirmation, $dto->resultReporting);
        $this->assertTrue($dto->requiresCheckIn);
        $this->assertEquals(60, $dto->checkInStartsBefore);
        $this->assertEquals($registrationOpens, $dto->registrationOpensAt);
        $this->assertEquals($registrationCloses, $dto->registrationClosesAt);
    }

    public function test_from_array_creates_dto_with_minimal_data(): void
    {
        $data = [
            'event_id' => 'event-789',
            'name' => 'Quick Tournament',
        ];

        $dto = CreateTournamentDTO::fromArray($data);

        $this->assertEquals('event-789', $dto->eventId);
        $this->assertEquals('Quick Tournament', $dto->name);
        $this->assertNull($dto->description);
    }

    public function test_from_array_creates_dto_with_full_data(): void
    {
        $data = [
            'event_id' => 'event-999',
            'name' => 'Full Tournament',
            'description' => 'A complete tournament setup',
            'max_rounds' => 6,
            'max_participants' => 64,
            'min_participants' => 16,
            'score_weights' => [
                ['name' => 'Win', 'key' => 'win', 'points' => 3.0],
            ],
            'tiebreakers' => ['buchholz', 'median_buchholz'],
            'allow_guests' => true,
            'allowed_roles' => ['admin'],
            'result_reporting' => 'players_trusted',
            'requires_check_in' => true,
            'check_in_starts_before' => 30,
            'registration_opens_at' => '2024-02-01 09:00:00',
            'registration_closes_at' => '2024-02-15 20:00:00',
        ];

        $dto = CreateTournamentDTO::fromArray($data);

        $this->assertEquals('event-999', $dto->eventId);
        $this->assertEquals('Full Tournament', $dto->name);
        $this->assertEquals('A complete tournament setup', $dto->description);
        $this->assertEquals(6, $dto->maxRounds);
        $this->assertEquals(64, $dto->maxParticipants);
        $this->assertEquals(16, $dto->minParticipants);
        $this->assertCount(1, $dto->scoreWeights);
        $this->assertEquals([Tiebreaker::Buchholz, Tiebreaker::MedianBuchholz], $dto->tiebreakers);
        $this->assertTrue($dto->allowGuests);
        $this->assertEquals(['admin'], $dto->allowedRoles);
        $this->assertEquals(ResultReporting::PlayersTrusted, $dto->resultReporting);
        $this->assertTrue($dto->requiresCheckIn);
        $this->assertEquals(30, $dto->checkInStartsBefore);
        $this->assertNotNull($dto->registrationOpensAt);
        $this->assertNotNull($dto->registrationClosesAt);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new CreateTournamentDTO(
            eventId: 'event-111',
            name: 'Array Test Tournament',
            description: 'Testing toArray',
            maxRounds: 4,
            maxParticipants: 16,
            minParticipants: 4,
            scoreWeights: [['name' => 'Win', 'key' => 'win', 'points' => 3.0]],
            tiebreakers: [Tiebreaker::Buchholz],
            allowGuests: false,
            allowedRoles: ['member'],
            resultReporting: ResultReporting::AdminOnly,
            requiresCheckIn: false,
            checkInStartsBefore: null,
            registrationOpensAt: new \DateTimeImmutable('2024-03-01 00:00:00'),
            registrationClosesAt: new \DateTimeImmutable('2024-03-10 23:59:59'),
        );

        $array = $dto->toArray();

        $this->assertEquals('event-111', $array['event_id']);
        $this->assertEquals('Array Test Tournament', $array['name']);
        $this->assertEquals('Testing toArray', $array['description']);
        $this->assertEquals(4, $array['max_rounds']);
        $this->assertEquals(16, $array['max_participants']);
        $this->assertEquals(4, $array['min_participants']);
        $this->assertEquals([['name' => 'Win', 'key' => 'win', 'points' => 3.0]], $array['score_weights']);
        $this->assertEquals(['buchholz'], $array['tiebreakers']);
        $this->assertFalse($array['allow_guests']);
        $this->assertEquals(['member'], $array['allowed_roles']);
        $this->assertEquals('admin_only', $array['result_reporting']);
        $this->assertFalse($array['requires_check_in']);
        $this->assertNull($array['check_in_starts_before']);
        $this->assertNotNull($array['registration_opens_at']);
        $this->assertNotNull($array['registration_closes_at']);
    }
}
