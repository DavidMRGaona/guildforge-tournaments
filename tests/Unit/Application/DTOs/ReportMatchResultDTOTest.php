<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs;

use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;

final class ReportMatchResultDTOTest extends TestCase
{
    public function test_can_create_dto_with_required_fields(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-123',
            result: MatchResult::PlayerOneWin,
            reportedById: 'user-456',
        );

        $this->assertEquals('match-123', $dto->matchId);
        $this->assertEquals(MatchResult::PlayerOneWin, $dto->result);
        $this->assertEquals('user-456', $dto->reportedById);
        $this->assertNull($dto->player1Score);
        $this->assertNull($dto->player2Score);
    }

    public function test_can_create_dto_with_scores(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-789',
            result: MatchResult::PlayerTwoWin,
            reportedById: 'user-abc',
            player1Score: 1,
            player2Score: 3,
        );

        $this->assertEquals('match-789', $dto->matchId);
        $this->assertEquals(MatchResult::PlayerTwoWin, $dto->result);
        $this->assertEquals('user-abc', $dto->reportedById);
        $this->assertEquals(1, $dto->player1Score);
        $this->assertEquals(3, $dto->player2Score);
    }

    public function test_can_create_dto_for_draw(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-draw',
            result: MatchResult::Draw,
            reportedById: 'user-xyz',
            player1Score: 2,
            player2Score: 2,
        );

        $this->assertEquals(MatchResult::Draw, $dto->result);
        $this->assertEquals(2, $dto->player1Score);
        $this->assertEquals(2, $dto->player2Score);
    }

    public function test_can_create_dto_for_bye(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-bye',
            result: MatchResult::Bye,
            reportedById: 'admin-001',
        );

        $this->assertEquals(MatchResult::Bye, $dto->result);
        $this->assertNull($dto->player1Score);
        $this->assertNull($dto->player2Score);
    }
}
