<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use PHPUnit\Framework\TestCase;

final class RoundResponseDTOTest extends TestCase
{
    public function test_can_create_dto(): void
    {
        $startedAt = new DateTimeImmutable('2024-01-20 10:00:00');

        $dto = new RoundResponseDTO(
            id: 'round-123',
            tournamentId: 'tournament-456',
            roundNumber: 1,
            status: RoundStatus::InProgress,
            matchCount: 8,
            completedMatchCount: 3,
            startedAt: $startedAt,
            completedAt: null,
        );

        $this->assertEquals('round-123', $dto->id);
        $this->assertEquals('tournament-456', $dto->tournamentId);
        $this->assertEquals(1, $dto->roundNumber);
        $this->assertEquals(RoundStatus::InProgress, $dto->status);
        $this->assertEquals(8, $dto->matchCount);
        $this->assertEquals(3, $dto->completedMatchCount);
        $this->assertEquals($startedAt, $dto->startedAt);
        $this->assertNull($dto->completedAt);
    }

    public function test_is_completed_returns_true_when_status_is_finished(): void
    {
        $dto = new RoundResponseDTO(
            id: 'round-finished',
            tournamentId: 'tournament',
            roundNumber: 1,
            status: RoundStatus::Finished,
            matchCount: 4,
            completedMatchCount: 4,
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->assertTrue($dto->isCompleted());
    }

    public function test_is_completed_returns_false_when_status_is_not_finished(): void
    {
        $pendingDto = new RoundResponseDTO(
            id: 'round-pending',
            tournamentId: 'tournament',
            roundNumber: 1,
            status: RoundStatus::Pending,
            matchCount: 4,
            completedMatchCount: 0,
            startedAt: null,
            completedAt: null,
        );

        $inProgressDto = new RoundResponseDTO(
            id: 'round-in-progress',
            tournamentId: 'tournament',
            roundNumber: 1,
            status: RoundStatus::InProgress,
            matchCount: 4,
            completedMatchCount: 2,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->assertFalse($pendingDto->isCompleted());
        $this->assertFalse($inProgressDto->isCompleted());
    }

    public function test_pending_match_count_calculation(): void
    {
        $dto = new RoundResponseDTO(
            id: 'round-pending-count',
            tournamentId: 'tournament',
            roundNumber: 2,
            status: RoundStatus::InProgress,
            matchCount: 8,
            completedMatchCount: 5,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->assertEquals(3, $dto->pendingMatchCount());
    }

    public function test_completion_percentage_calculation(): void
    {
        $dto = new RoundResponseDTO(
            id: 'round-percentage',
            tournamentId: 'tournament',
            roundNumber: 3,
            status: RoundStatus::InProgress,
            matchCount: 8,
            completedMatchCount: 6,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->assertEquals(75.0, $dto->completionPercentage());
    }

    public function test_completion_percentage_returns_zero_when_no_matches(): void
    {
        $dto = new RoundResponseDTO(
            id: 'round-no-matches',
            tournamentId: 'tournament',
            roundNumber: 1,
            status: RoundStatus::Pending,
            matchCount: 0,
            completedMatchCount: 0,
            startedAt: null,
            completedAt: null,
        );

        $this->assertEquals(0.0, $dto->completionPercentage());
    }

    public function test_completion_percentage_returns_100_when_all_complete(): void
    {
        $dto = new RoundResponseDTO(
            id: 'round-complete',
            tournamentId: 'tournament',
            roundNumber: 1,
            status: RoundStatus::Finished,
            matchCount: 10,
            completedMatchCount: 10,
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->assertEquals(100.0, $dto->completionPercentage());
    }

    public function test_dto_properties_are_accessible(): void
    {
        // Note: toArray() calls label() on enums which requires Laravel's translator.
        // This test verifies DTO properties without triggering the translator.
        $startedAt = new DateTimeImmutable('2024-02-01 11:00:00');
        $completedAt = new DateTimeImmutable('2024-02-01 15:30:00');

        $dto = new RoundResponseDTO(
            id: 'round-array',
            tournamentId: 'tournament-array',
            roundNumber: 4,
            status: RoundStatus::Finished,
            matchCount: 6,
            completedMatchCount: 6,
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        $this->assertEquals('round-array', $dto->id);
        $this->assertEquals('tournament-array', $dto->tournamentId);
        $this->assertEquals(4, $dto->roundNumber);
        $this->assertEquals(RoundStatus::Finished, $dto->status);
        $this->assertEquals('finished', $dto->status->value);
        $this->assertEquals(6, $dto->matchCount);
        $this->assertEquals(6, $dto->completedMatchCount);
        $this->assertEquals(0, $dto->pendingMatchCount());
        $this->assertEquals(100.0, $dto->completionPercentage());
        $this->assertTrue($dto->isCompleted());
        $this->assertEquals($startedAt, $dto->startedAt);
        $this->assertEquals($completedAt, $dto->completedAt);
    }
}
