<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use PHPUnit\Framework\TestCase;

final class TournamentResponseDTOTest extends TestCase
{
    public function test_can_create_dto(): void
    {
        $createdAt = new DateTimeImmutable('2024-01-01 10:00:00');
        $registrationOpens = new DateTimeImmutable('2024-01-05 10:00:00');
        $registrationCloses = new DateTimeImmutable('2024-01-15 18:00:00');

        $dto = new TournamentResponseDTO(
            id: 'tournament-123',
            eventId: 'event-456',
            name: 'Championship 2024',
            slug: 'championship-2024',
            description: 'Annual championship',
            imagePublicId: null,
            status: TournamentStatus::Draft,
            maxRounds: 5,
            currentRound: 0,
            maxParticipants: 32,
            minParticipants: 8,
            participantCount: 0,
            scoreWeights: [
                ['name' => 'Win', 'key' => 'win', 'points' => 3.0],
            ],
            tiebreakers: ['buchholz', 'progressive'],
            allowGuests: false,
            requiresManualConfirmation: false,
            allowedRoles: ['member'],
            resultReporting: ResultReporting::AdminOnly,
            requiresCheckIn: true,
            checkInStartsBefore: 60,
            registrationOpensAt: $registrationOpens,
            registrationClosesAt: $registrationCloses,
            startedAt: null,
            completedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );

        $this->assertEquals('tournament-123', $dto->id);
        $this->assertEquals('event-456', $dto->eventId);
        $this->assertEquals('Championship 2024', $dto->name);
        $this->assertEquals('championship-2024', $dto->slug);
        $this->assertEquals('Annual championship', $dto->description);
        $this->assertEquals(TournamentStatus::Draft, $dto->status);
        $this->assertEquals(5, $dto->maxRounds);
        $this->assertEquals(0, $dto->currentRound);
        $this->assertEquals(32, $dto->maxParticipants);
        $this->assertEquals(8, $dto->minParticipants);
        $this->assertEquals(0, $dto->participantCount);
        $this->assertCount(1, $dto->scoreWeights);
        $this->assertEquals(['buchholz', 'progressive'], $dto->tiebreakers);
        $this->assertFalse($dto->allowGuests);
        $this->assertEquals(['member'], $dto->allowedRoles);
        $this->assertEquals(ResultReporting::AdminOnly, $dto->resultReporting);
        $this->assertTrue($dto->requiresCheckIn);
        $this->assertEquals(60, $dto->checkInStartsBefore);
        $this->assertEquals($registrationOpens, $dto->registrationOpensAt);
        $this->assertEquals($registrationCloses, $dto->registrationClosesAt);
        $this->assertNull($dto->startedAt);
        $this->assertNull($dto->completedAt);
    }

    public function test_is_registration_open_returns_true_when_status_is_registration_open(): void
    {
        $dto = $this->createDtoWithStatus(TournamentStatus::RegistrationOpen);

        $this->assertTrue($dto->isRegistrationOpen());
    }

    public function test_is_registration_open_returns_false_for_other_statuses(): void
    {
        $draftDto = $this->createDtoWithStatus(TournamentStatus::Draft);
        $inProgressDto = $this->createDtoWithStatus(TournamentStatus::InProgress);
        $finishedDto = $this->createDtoWithStatus(TournamentStatus::Finished);

        $this->assertFalse($draftDto->isRegistrationOpen());
        $this->assertFalse($inProgressDto->isRegistrationOpen());
        $this->assertFalse($finishedDto->isRegistrationOpen());
    }

    public function test_is_in_progress_returns_true_when_status_is_in_progress(): void
    {
        $dto = $this->createDtoWithStatus(TournamentStatus::InProgress);

        $this->assertTrue($dto->isInProgress());
    }

    public function test_is_finished_returns_true_when_status_is_finished(): void
    {
        $dto = $this->createDtoWithStatus(TournamentStatus::Finished);

        $this->assertTrue($dto->isFinished());
    }

    public function test_has_capacity_returns_true_when_under_max_participants(): void
    {
        $dto = $this->createDtoWithCapacity(maxParticipants: 32, participantCount: 20);

        $this->assertTrue($dto->hasCapacity());
    }

    public function test_has_capacity_returns_false_when_at_max_participants(): void
    {
        $dto = $this->createDtoWithCapacity(maxParticipants: 32, participantCount: 32);

        $this->assertFalse($dto->hasCapacity());
    }

    public function test_has_capacity_returns_true_when_max_participants_is_null(): void
    {
        $dto = $this->createDtoWithCapacity(maxParticipants: null, participantCount: 100);

        $this->assertTrue($dto->hasCapacity());
    }

    public function test_recommended_rounds_calculation(): void
    {
        $dto8 = $this->createDtoWithCapacity(maxParticipants: null, participantCount: 8);
        $dto16 = $this->createDtoWithCapacity(maxParticipants: null, participantCount: 16);
        $dto32 = $this->createDtoWithCapacity(maxParticipants: null, participantCount: 32);

        $this->assertEquals(3, $dto8->recommendedRounds());
        $this->assertEquals(4, $dto16->recommendedRounds());
        $this->assertEquals(5, $dto32->recommendedRounds());
    }

    public function test_dto_properties_are_accessible(): void
    {
        // Note: toArray() calls label() on enums which requires Laravel's translator.
        // This test verifies DTO properties without triggering the translator.
        $createdAt = new DateTimeImmutable('2024-01-01 10:00:00');
        $dto = new TournamentResponseDTO(
            id: 'tournament-array',
            eventId: 'event-array',
            name: 'Array Test',
            slug: 'array-test',
            description: null,
            imagePublicId: null,
            status: TournamentStatus::RegistrationOpen,
            maxRounds: null,
            currentRound: 0,
            maxParticipants: 16,
            minParticipants: 4,
            participantCount: 8,
            scoreWeights: [],
            tiebreakers: [],
            allowGuests: true,
            requiresManualConfirmation: false,
            allowedRoles: [],
            resultReporting: ResultReporting::PlayersTrusted,
            requiresCheckIn: false,
            checkInStartsBefore: null,
            registrationOpensAt: null,
            registrationClosesAt: null,
            startedAt: null,
            completedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );

        $this->assertEquals('tournament-array', $dto->id);
        $this->assertEquals('event-array', $dto->eventId);
        $this->assertEquals('Array Test', $dto->name);
        $this->assertEquals('array-test', $dto->slug);
        $this->assertNull($dto->description);
        $this->assertEquals(TournamentStatus::RegistrationOpen, $dto->status);
        $this->assertEquals('registration_open', $dto->status->value);
        $this->assertNull($dto->maxRounds);
        $this->assertEquals(0, $dto->currentRound);
        $this->assertEquals(16, $dto->maxParticipants);
        $this->assertEquals(4, $dto->minParticipants);
        $this->assertEquals(8, $dto->participantCount);
        $this->assertTrue($dto->allowGuests);
        $this->assertEquals(ResultReporting::PlayersTrusted, $dto->resultReporting);
        $this->assertEquals('players_trusted', $dto->resultReporting->value);
        $this->assertFalse($dto->requiresCheckIn);
        $this->assertTrue($dto->isRegistrationOpen());
        $this->assertTrue($dto->hasCapacity());
        $this->assertEquals(3, $dto->recommendedRounds());
    }

    private function createDtoWithStatus(TournamentStatus $status): TournamentResponseDTO
    {
        return new TournamentResponseDTO(
            id: 'test',
            eventId: 'event',
            name: 'Test',
            slug: 'test',
            description: null,
            imagePublicId: null,
            status: $status,
            maxRounds: null,
            currentRound: 0,
            maxParticipants: null,
            minParticipants: null,
            participantCount: 0,
            scoreWeights: [],
            tiebreakers: [],
            allowGuests: false,
            requiresManualConfirmation: false,
            allowedRoles: [],
            resultReporting: ResultReporting::AdminOnly,
            requiresCheckIn: false,
            checkInStartsBefore: null,
            registrationOpensAt: null,
            registrationClosesAt: null,
            startedAt: null,
            completedAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    private function createDtoWithCapacity(?int $maxParticipants, int $participantCount): TournamentResponseDTO
    {
        return new TournamentResponseDTO(
            id: 'test',
            eventId: 'event',
            name: 'Test',
            slug: 'test',
            description: null,
            imagePublicId: null,
            status: TournamentStatus::RegistrationOpen,
            maxRounds: null,
            currentRound: 0,
            maxParticipants: $maxParticipants,
            minParticipants: null,
            participantCount: $participantCount,
            scoreWeights: [],
            tiebreakers: [],
            allowGuests: false,
            requiresManualConfirmation: false,
            allowedRoles: [],
            resultReporting: ResultReporting::AdminOnly,
            requiresCheckIn: false,
            checkInStartsBefore: null,
            registrationOpensAt: null,
            registrationClosesAt: null,
            startedAt: null,
            completedAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
