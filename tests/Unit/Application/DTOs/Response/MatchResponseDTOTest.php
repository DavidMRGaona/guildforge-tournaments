<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;
use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;

final class MatchResponseDTOTest extends TestCase
{
    public function test_can_create_dto_for_pending_match(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match-123',
            roundId: 'round-456',
            player1Id: 'participant-1',
            player1Name: 'Player One',
            player2Id: 'participant-2',
            player2Name: 'Player Two',
            tableNumber: 1,
            result: MatchResult::NotPlayed,
            player1Score: null,
            player2Score: null,
            reportedById: null,
            reportedByName: null,
            reportedAt: null,
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertEquals('match-123', $dto->id);
        $this->assertEquals('round-456', $dto->roundId);
        $this->assertEquals('participant-1', $dto->player1Id);
        $this->assertEquals('Player One', $dto->player1Name);
        $this->assertEquals('participant-2', $dto->player2Id);
        $this->assertEquals('Player Two', $dto->player2Name);
        $this->assertEquals(1, $dto->tableNumber);
        $this->assertEquals(MatchResult::NotPlayed, $dto->result);
        $this->assertNull($dto->player1Score);
        $this->assertNull($dto->player2Score);
        $this->assertNull($dto->reportedById);
        $this->assertNull($dto->reportedAt);
        $this->assertNull($dto->confirmedById);
        $this->assertNull($dto->confirmedAt);
        $this->assertFalse($dto->isDisputed);
    }

    public function test_can_create_dto_for_reported_match(): void
    {
        $reportedAt = new DateTimeImmutable('2024-01-20 14:30:00');

        $dto = new MatchResponseDTO(
            id: 'match-reported',
            roundId: 'round-123',
            player1Id: 'p1',
            player1Name: 'Alice',
            player2Id: 'p2',
            player2Name: 'Bob',
            tableNumber: 3,
            result: MatchResult::PlayerOneWin,
            player1Score: 2,
            player2Score: 1,
            reportedById: 'p1',
            reportedByName: 'Alice',
            reportedAt: $reportedAt,
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertEquals(MatchResult::PlayerOneWin, $dto->result);
        $this->assertEquals(2, $dto->player1Score);
        $this->assertEquals(1, $dto->player2Score);
        $this->assertEquals('p1', $dto->reportedById);
        $this->assertEquals('Alice', $dto->reportedByName);
        $this->assertEquals($reportedAt, $dto->reportedAt);
    }

    public function test_can_create_dto_for_confirmed_match(): void
    {
        $reportedAt = new DateTimeImmutable('2024-01-20 14:30:00');
        $confirmedAt = new DateTimeImmutable('2024-01-20 14:35:00');

        $dto = new MatchResponseDTO(
            id: 'match-confirmed',
            roundId: 'round-123',
            player1Id: 'p1',
            player1Name: 'Alice',
            player2Id: 'p2',
            player2Name: 'Bob',
            tableNumber: 2,
            result: MatchResult::PlayerTwoWin,
            player1Score: 0,
            player2Score: 2,
            reportedById: 'p1',
            reportedByName: 'Alice',
            reportedAt: $reportedAt,
            confirmedById: 'p2',
            confirmedByName: 'Bob',
            confirmedAt: $confirmedAt,
            isDisputed: false,
        );

        $this->assertEquals('p2', $dto->confirmedById);
        $this->assertEquals('Bob', $dto->confirmedByName);
        $this->assertEquals($confirmedAt, $dto->confirmedAt);
    }

    public function test_can_create_dto_for_bye_match(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match-bye',
            roundId: 'round-123',
            player1Id: 'p1',
            player1Name: 'Solo Player',
            player2Id: null,
            player2Name: null,
            tableNumber: null,
            result: MatchResult::Bye,
            player1Score: null,
            player2Score: null,
            reportedById: 'admin',
            reportedByName: 'Admin',
            reportedAt: new DateTimeImmutable(),
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertNull($dto->player2Id);
        $this->assertNull($dto->player2Name);
        $this->assertNull($dto->tableNumber);
        $this->assertEquals(MatchResult::Bye, $dto->result);
    }

    public function test_is_bye_returns_true_for_bye_match(): void
    {
        $dto = $this->createMatchWithResult(MatchResult::Bye);

        $this->assertTrue($dto->isBye());
    }

    public function test_is_bye_returns_false_for_regular_match(): void
    {
        $dto = $this->createMatchWithResult(MatchResult::PlayerOneWin);

        $this->assertFalse($dto->isBye());
    }

    public function test_is_reported_returns_true_when_reported_at_is_set(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: 'p2',
            player2Name: 'P2',
            tableNumber: 1,
            result: MatchResult::Draw,
            player1Score: 1,
            player2Score: 1,
            reportedById: 'p1',
            reportedByName: 'P1',
            reportedAt: new DateTimeImmutable(),
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertTrue($dto->isReported());
    }

    public function test_is_reported_returns_false_when_reported_at_is_null(): void
    {
        $dto = $this->createMatchWithResult(MatchResult::NotPlayed);

        $this->assertFalse($dto->isReported());
    }

    public function test_is_confirmed_returns_true_when_confirmed_at_is_set(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: 'p2',
            player2Name: 'P2',
            tableNumber: 1,
            result: MatchResult::PlayerOneWin,
            player1Score: 2,
            player2Score: 0,
            reportedById: 'p1',
            reportedByName: 'P1',
            reportedAt: new DateTimeImmutable(),
            confirmedById: 'p2',
            confirmedByName: 'P2',
            confirmedAt: new DateTimeImmutable(),
            isDisputed: false,
        );

        $this->assertTrue($dto->isConfirmed());
    }

    public function test_is_confirmed_returns_false_when_confirmed_at_is_null(): void
    {
        $dto = $this->createMatchWithResult(MatchResult::PlayerOneWin);

        $this->assertFalse($dto->isConfirmed());
    }

    public function test_needs_confirmation_returns_true_when_reported_but_not_confirmed(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: 'p2',
            player2Name: 'P2',
            tableNumber: 1,
            result: MatchResult::PlayerOneWin,
            player1Score: 2,
            player2Score: 0,
            reportedById: 'p1',
            reportedByName: 'P1',
            reportedAt: new DateTimeImmutable(),
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertTrue($dto->needsConfirmation());
    }

    public function test_needs_confirmation_returns_false_when_confirmed(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: 'p2',
            player2Name: 'P2',
            tableNumber: 1,
            result: MatchResult::PlayerOneWin,
            player1Score: 2,
            player2Score: 0,
            reportedById: 'p1',
            reportedByName: 'P1',
            reportedAt: new DateTimeImmutable(),
            confirmedById: 'p2',
            confirmedByName: 'P2',
            confirmedAt: new DateTimeImmutable(),
            isDisputed: false,
        );

        $this->assertFalse($dto->needsConfirmation());
    }

    public function test_needs_confirmation_returns_false_for_bye(): void
    {
        $dto = new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: null,
            player2Name: null,
            tableNumber: null,
            result: MatchResult::Bye,
            player1Score: null,
            player2Score: null,
            reportedById: 'admin',
            reportedByName: 'Admin',
            reportedAt: new DateTimeImmutable(),
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );

        $this->assertFalse($dto->needsConfirmation());
    }

    public function test_dto_properties_are_accessible(): void
    {
        // Note: toArray() calls label() on enums which requires Laravel's translator.
        // This test verifies DTO properties without triggering the translator.
        $reportedAt = new DateTimeImmutable('2024-02-01 10:00:00');
        $confirmedAt = new DateTimeImmutable('2024-02-01 10:05:00');

        $dto = new MatchResponseDTO(
            id: 'match-array',
            roundId: 'round-array',
            player1Id: 'p1-array',
            player1Name: 'Player 1',
            player2Id: 'p2-array',
            player2Name: 'Player 2',
            tableNumber: 5,
            result: MatchResult::Draw,
            player1Score: 2,
            player2Score: 2,
            reportedById: 'p1-array',
            reportedByName: 'Player 1',
            reportedAt: $reportedAt,
            confirmedById: 'p2-array',
            confirmedByName: 'Player 2',
            confirmedAt: $confirmedAt,
            isDisputed: false,
        );

        $this->assertEquals('match-array', $dto->id);
        $this->assertEquals('round-array', $dto->roundId);
        $this->assertEquals('p1-array', $dto->player1Id);
        $this->assertEquals('Player 1', $dto->player1Name);
        $this->assertEquals('p2-array', $dto->player2Id);
        $this->assertEquals('Player 2', $dto->player2Name);
        $this->assertEquals(5, $dto->tableNumber);
        $this->assertEquals(MatchResult::Draw, $dto->result);
        $this->assertEquals('draw', $dto->result->value);
        $this->assertEquals(2, $dto->player1Score);
        $this->assertEquals(2, $dto->player2Score);
        $this->assertEquals('p1-array', $dto->reportedById);
        $this->assertEquals('Player 1', $dto->reportedByName);
        $this->assertEquals($reportedAt, $dto->reportedAt);
        $this->assertEquals('p2-array', $dto->confirmedById);
        $this->assertEquals('Player 2', $dto->confirmedByName);
        $this->assertEquals($confirmedAt, $dto->confirmedAt);
        $this->assertFalse($dto->isDisputed);
        $this->assertFalse($dto->isBye());
        $this->assertTrue($dto->isReported());
        $this->assertTrue($dto->isConfirmed());
        $this->assertFalse($dto->needsConfirmation());
    }

    private function createMatchWithResult(MatchResult $result): MatchResponseDTO
    {
        return new MatchResponseDTO(
            id: 'match',
            roundId: 'round',
            player1Id: 'p1',
            player1Name: 'P1',
            player2Id: $result === MatchResult::Bye ? null : 'p2',
            player2Name: $result === MatchResult::Bye ? null : 'P2',
            tableNumber: $result === MatchResult::Bye ? null : 1,
            result: $result,
            player1Score: null,
            player2Score: null,
            reportedById: null,
            reportedByName: null,
            reportedAt: null,
            confirmedById: null,
            confirmedByName: null,
            confirmedAt: null,
            isDisputed: false,
        );
    }
}
