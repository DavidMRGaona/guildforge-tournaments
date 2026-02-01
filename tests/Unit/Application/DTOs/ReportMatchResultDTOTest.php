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

    public function test_from_array_creates_dto(): void
    {
        $data = [
            'match_id' => 'match-array',
            'result' => 'player_one_win',
            'reported_by_id' => 'user-reporter',
            'player_1_score' => 2,
            'player_2_score' => 0,
        ];

        $dto = ReportMatchResultDTO::fromArray($data);

        $this->assertEquals('match-array', $dto->matchId);
        $this->assertEquals(MatchResult::PlayerOneWin, $dto->result);
        $this->assertEquals('user-reporter', $dto->reportedById);
        $this->assertEquals(2, $dto->player1Score);
        $this->assertEquals(0, $dto->player2Score);
    }

    public function test_from_array_creates_dto_without_scores(): void
    {
        $data = [
            'match_id' => 'match-no-score',
            'result' => 'double_loss',
            'reported_by_id' => 'admin-002',
        ];

        $dto = ReportMatchResultDTO::fromArray($data);

        $this->assertEquals('match-no-score', $dto->matchId);
        $this->assertEquals(MatchResult::DoubleLoss, $dto->result);
        $this->assertNull($dto->player1Score);
        $this->assertNull($dto->player2Score);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-toarray',
            result: MatchResult::PlayerTwoWin,
            reportedById: 'user-toarray',
            player1Score: 0,
            player2Score: 3,
        );

        $array = $dto->toArray();

        $this->assertEquals('match-toarray', $array['match_id']);
        $this->assertEquals('player_two_win', $array['result']);
        $this->assertEquals('user-toarray', $array['reported_by_id']);
        $this->assertEquals(0, $array['player_1_score']);
        $this->assertEquals(3, $array['player_2_score']);
    }

    public function test_to_array_omits_null_scores(): void
    {
        $dto = new ReportMatchResultDTO(
            matchId: 'match-null-scores',
            result: MatchResult::Bye,
            reportedById: 'admin-bye',
        );

        $array = $dto->toArray();

        $this->assertEquals('match-null-scores', $array['match_id']);
        $this->assertEquals('bye', $array['result']);
        $this->assertNull($array['player_1_score']);
        $this->assertNull($array['player_2_score']);
    }
}
