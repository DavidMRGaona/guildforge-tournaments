<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MatchHistoryTest extends TestCase
{
    public function test_it_creates_match_history_entry(): void
    {
        $matchId = Uuid::uuid4()->toString();
        $changedById = Uuid::uuid4()->toString();
        $changedAt = new DateTimeImmutable('2026-11-20 10:00:00');

        $history = new MatchHistory(
            id: Uuid::uuid4()->toString(),
            matchId: $matchId,
            previousResult: MatchResult::PlayerOneWin,
            newResult: MatchResult::Draw,
            previousPlayer1Score: 2,
            newPlayer1Score: 1,
            previousPlayer2Score: 1,
            newPlayer2Score: 1,
            changedById: $changedById,
            reason: 'Score correction after review',
            changedAt: $changedAt,
            action: MatchHistoryAction::Reset,
        );

        $this->assertEquals($matchId, $history->matchId());
        $this->assertEquals(MatchResult::PlayerOneWin, $history->previousResult());
        $this->assertEquals(MatchResult::Draw, $history->newResult());
        $this->assertEquals(2, $history->previousPlayer1Score());
        $this->assertEquals(1, $history->newPlayer1Score());
        $this->assertEquals(1, $history->previousPlayer2Score());
        $this->assertEquals(1, $history->newPlayer2Score());
        $this->assertEquals($changedById, $history->changedById());
        $this->assertEquals('Score correction after review', $history->reason());
        $this->assertEquals($changedAt, $history->changedAt());
        $this->assertSame(MatchHistoryAction::Reset, $history->action());
    }

    public function test_it_creates_from_result_change(): void
    {
        $matchId = Uuid::uuid4()->toString();
        $changedById = Uuid::uuid4()->toString();

        $history = MatchHistory::fromResultChange(
            matchId: $matchId,
            previousResult: MatchResult::NotPlayed,
            newResult: MatchResult::PlayerOneWin,
            previousPlayer1Score: null,
            newPlayer1Score: 2,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: $changedById,
            reason: null,
            changedAt: new DateTimeImmutable('2030-05-01 10:00:00'),
            action: MatchHistoryAction::Confirmed,
        );

        $this->assertSame(MatchHistoryAction::Confirmed, $history->action());
        $this->assertEquals(new DateTimeImmutable('2030-05-01 10:00:00'), $history->changedAt());
        $this->assertNotEmpty($history->id());
        $this->assertEquals($matchId, $history->matchId());
        $this->assertEquals(MatchResult::NotPlayed, $history->previousResult());
        $this->assertEquals(MatchResult::PlayerOneWin, $history->newResult());
        $this->assertNull($history->reason());
    }

    public function test_previous_result_can_be_null(): void
    {
        $history = MatchHistory::fromResultChange(
            matchId: Uuid::uuid4()->toString(),
            previousResult: null,
            newResult: MatchResult::PlayerOneWin,
            previousPlayer1Score: null,
            newPlayer1Score: 2,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: Uuid::uuid4()->toString(),
            reason: null,
            changedAt: new DateTimeImmutable('2030-05-01 10:00:00'),
            action: MatchHistoryAction::Recorded,
        );

        $this->assertNull($history->previousResult());
    }
}
