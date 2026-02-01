<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\MatchHistoryResponseDTO;
use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;

final class MatchHistoryResponseDTOTest extends TestCase
{
    public function test_can_create_dto(): void
    {
        $changedAt = new DateTimeImmutable('2024-01-20 15:00:00');

        $dto = new MatchHistoryResponseDTO(
            id: 'history-123',
            matchId: 'match-456',
            previousResult: MatchResult::PlayerOneWin,
            newResult: MatchResult::PlayerTwoWin,
            previousPlayer1Score: 2,
            newPlayer1Score: 1,
            previousPlayer2Score: 0,
            newPlayer2Score: 2,
            changedById: 'admin-789',
            changedByName: 'Admin User',
            reason: 'Score correction after video review',
            changedAt: $changedAt,
        );

        $this->assertEquals('history-123', $dto->id);
        $this->assertEquals('match-456', $dto->matchId);
        $this->assertEquals(MatchResult::PlayerOneWin, $dto->previousResult);
        $this->assertEquals(MatchResult::PlayerTwoWin, $dto->newResult);
        $this->assertEquals(2, $dto->previousPlayer1Score);
        $this->assertEquals(1, $dto->newPlayer1Score);
        $this->assertEquals(0, $dto->previousPlayer2Score);
        $this->assertEquals(2, $dto->newPlayer2Score);
        $this->assertEquals('admin-789', $dto->changedById);
        $this->assertEquals('Admin User', $dto->changedByName);
        $this->assertEquals('Score correction after video review', $dto->reason);
        $this->assertEquals($changedAt, $dto->changedAt);
    }

    public function test_can_create_dto_for_first_report(): void
    {
        $changedAt = new DateTimeImmutable('2024-01-20 14:00:00');

        $dto = new MatchHistoryResponseDTO(
            id: 'history-first',
            matchId: 'match-first',
            previousResult: null,
            newResult: MatchResult::Draw,
            previousPlayer1Score: null,
            newPlayer1Score: 1,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: 'user-reporter',
            changedByName: 'Player One',
            reason: null,
            changedAt: $changedAt,
        );

        $this->assertNull($dto->previousResult);
        $this->assertEquals(MatchResult::Draw, $dto->newResult);
        $this->assertNull($dto->previousPlayer1Score);
        $this->assertEquals(1, $dto->newPlayer1Score);
        $this->assertNull($dto->reason);
    }

    public function test_is_initial_report_returns_true_when_no_previous_result(): void
    {
        $dto = new MatchHistoryResponseDTO(
            id: 'history',
            matchId: 'match',
            previousResult: null,
            newResult: MatchResult::PlayerOneWin,
            previousPlayer1Score: null,
            newPlayer1Score: 2,
            previousPlayer2Score: null,
            newPlayer2Score: 0,
            changedById: 'user',
            changedByName: 'User',
            reason: null,
            changedAt: new DateTimeImmutable(),
        );

        $this->assertTrue($dto->isInitialReport());
    }

    public function test_is_initial_report_returns_false_when_previous_result_exists(): void
    {
        $dto = new MatchHistoryResponseDTO(
            id: 'history',
            matchId: 'match',
            previousResult: MatchResult::PlayerOneWin,
            newResult: MatchResult::Draw,
            previousPlayer1Score: 2,
            newPlayer1Score: 1,
            previousPlayer2Score: 0,
            newPlayer2Score: 1,
            changedById: 'admin',
            changedByName: 'Admin',
            reason: 'Correction',
            changedAt: new DateTimeImmutable(),
        );

        $this->assertFalse($dto->isInitialReport());
    }

    public function test_result_changed_returns_true_when_results_differ(): void
    {
        $dto = new MatchHistoryResponseDTO(
            id: 'history',
            matchId: 'match',
            previousResult: MatchResult::PlayerOneWin,
            newResult: MatchResult::PlayerTwoWin,
            previousPlayer1Score: 2,
            newPlayer1Score: 0,
            previousPlayer2Score: 0,
            newPlayer2Score: 2,
            changedById: 'admin',
            changedByName: 'Admin',
            reason: null,
            changedAt: new DateTimeImmutable(),
        );

        $this->assertTrue($dto->resultChanged());
    }

    public function test_result_changed_returns_false_when_results_same(): void
    {
        $dto = new MatchHistoryResponseDTO(
            id: 'history',
            matchId: 'match',
            previousResult: MatchResult::Draw,
            newResult: MatchResult::Draw,
            previousPlayer1Score: 1,
            newPlayer1Score: 2,
            previousPlayer2Score: 1,
            newPlayer2Score: 2,
            changedById: 'admin',
            changedByName: 'Admin',
            reason: 'Score update only',
            changedAt: new DateTimeImmutable(),
        );

        $this->assertFalse($dto->resultChanged());
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $changedAt = new DateTimeImmutable('2024-02-01 16:00:00');

        $dto = new MatchHistoryResponseDTO(
            id: 'history-array',
            matchId: 'match-array',
            previousResult: MatchResult::NotPlayed,
            newResult: MatchResult::PlayerOneWin,
            previousPlayer1Score: null,
            newPlayer1Score: 3,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: 'user-array',
            changedByName: 'Array User',
            reason: 'Initial report',
            changedAt: $changedAt,
        );

        $array = $dto->toArray();

        $this->assertEquals('history-array', $array['id']);
        $this->assertEquals('match-array', $array['match_id']);
        $this->assertEquals('not_played', $array['previous_result']);
        $this->assertEquals('player_one_win', $array['new_result']);
        $this->assertNull($array['previous_player_1_score']);
        $this->assertEquals(3, $array['new_player_1_score']);
        $this->assertNull($array['previous_player_2_score']);
        $this->assertEquals(1, $array['new_player_2_score']);
        $this->assertEquals('user-array', $array['changed_by_id']);
        $this->assertEquals('Array User', $array['changed_by_name']);
        $this->assertEquals('Initial report', $array['reason']);
        $this->assertNotNull($array['changed_at']);
        $this->assertTrue($array['result_changed']);
    }
}
