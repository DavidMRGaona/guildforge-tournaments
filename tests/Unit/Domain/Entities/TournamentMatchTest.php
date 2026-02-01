<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use PHPUnit\Framework\TestCase;

final class TournamentMatchTest extends TestCase
{
    private function createMatch(
        ?string $player2Id = '660e8400-e29b-41d4-a716-446655440002',
        MatchResult $result = MatchResult::NotPlayed,
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: '550e8400-e29b-41d4-a716-446655440000',
            player1Id: '660e8400-e29b-41d4-a716-446655440001',
            player2Id: $player2Id,
            result: $result,
        );
    }

    public function test_it_creates_match_with_required_fields(): void
    {
        $match = $this->createMatch();

        $this->assertInstanceOf(MatchId::class, $match->id());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $match->roundId());
        $this->assertEquals('660e8400-e29b-41d4-a716-446655440001', $match->player1Id());
        $this->assertEquals('660e8400-e29b-41d4-a716-446655440002', $match->player2Id());
        $this->assertEquals(MatchResult::NotPlayed, $match->result());
    }

    public function test_bye_match_has_no_player2(): void
    {
        $byeMatch = $this->createMatch(player2Id: null, result: MatchResult::Bye);

        $this->assertNull($byeMatch->player2Id());
        $this->assertTrue($byeMatch->isBye());
    }

    public function test_regular_match_is_not_bye(): void
    {
        $match = $this->createMatch();

        $this->assertFalse($match->isBye());
    }

    public function test_is_completed_returns_correct_values(): void
    {
        $notPlayed = $this->createMatch(result: MatchResult::NotPlayed);
        $playerOneWin = $this->createMatch(result: MatchResult::PlayerOneWin);
        $draw = $this->createMatch(result: MatchResult::Draw);

        $this->assertFalse($notPlayed->isCompleted());
        $this->assertTrue($playerOneWin->isCompleted());
        $this->assertTrue($draw->isCompleted());
    }

    public function test_report_result(): void
    {
        $match = $this->createMatch();
        $reporterId = '770e8400-e29b-41d4-a716-446655440003';

        $match->reportResult(
            result: MatchResult::PlayerOneWin,
            reportedById: $reporterId,
            player1Score: 2,
            player2Score: 1
        );

        $this->assertEquals(MatchResult::PlayerOneWin, $match->result());
        $this->assertEquals(2, $match->player1Score());
        $this->assertEquals(1, $match->player2Score());
        $this->assertEquals($reporterId, $match->reportedById());
        $this->assertInstanceOf(DateTimeImmutable::class, $match->reportedAt());
    }

    public function test_confirm_result(): void
    {
        $match = $this->createMatch();
        $match->reportResult(MatchResult::PlayerOneWin, 'reporter-id');

        $confirmerId = '770e8400-e29b-41d4-a716-446655440003';
        $match->confirmResult($confirmerId);

        $this->assertTrue($match->isConfirmed());
        $this->assertEquals($confirmerId, $match->confirmedById());
        $this->assertInstanceOf(DateTimeImmutable::class, $match->confirmedAt());
    }

    public function test_dispute_result(): void
    {
        $match = $this->createMatch();
        $match->reportResult(MatchResult::PlayerOneWin, 'reporter-id');

        $match->dispute();

        $this->assertTrue($match->isDisputed());
    }

    public function test_reset_result(): void
    {
        $match = $this->createMatch();
        $match->reportResult(MatchResult::PlayerOneWin, 'reporter-id', 2, 1);
        $match->confirmResult('confirmer-id');

        $match->resetResult();

        $this->assertEquals(MatchResult::NotPlayed, $match->result());
        $this->assertNull($match->player1Score());
        $this->assertNull($match->player2Score());
        $this->assertNull($match->reportedById());
        $this->assertNull($match->reportedAt());
        $this->assertNull($match->confirmedById());
        $this->assertNull($match->confirmedAt());
        $this->assertFalse($match->isDisputed());
    }

    public function test_involves_participant(): void
    {
        $match = $this->createMatch();

        $this->assertTrue($match->involvesParticipant('660e8400-e29b-41d4-a716-446655440001'));
        $this->assertTrue($match->involvesParticipant('660e8400-e29b-41d4-a716-446655440002'));
        $this->assertFalse($match->involvesParticipant('some-other-id'));
    }

    public function test_table_number(): void
    {
        $match = new TournamentMatch(
            id: MatchId::generate(),
            roundId: '550e8400-e29b-41d4-a716-446655440000',
            player1Id: '660e8400-e29b-41d4-a716-446655440001',
            player2Id: '660e8400-e29b-41d4-a716-446655440002',
            result: MatchResult::NotPlayed,
            tableNumber: 5,
        );

        $this->assertEquals(5, $match->tableNumber());
    }

    public function test_set_table_number(): void
    {
        $match = $this->createMatch();

        $match->setTableNumber(3);

        $this->assertEquals(3, $match->tableNumber());
    }
}
