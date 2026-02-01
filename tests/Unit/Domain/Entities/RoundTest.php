<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use PHPUnit\Framework\TestCase;

final class RoundTest extends TestCase
{
    private function createRound(RoundStatus $status = RoundStatus::Pending): Round
    {
        return new Round(
            id: RoundId::generate(),
            tournamentId: '550e8400-e29b-41d4-a716-446655440000',
            roundNumber: 1,
            status: $status,
        );
    }

    public function test_it_creates_round_with_required_fields(): void
    {
        $round = $this->createRound();

        $this->assertInstanceOf(RoundId::class, $round->id());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $round->tournamentId());
        $this->assertEquals(1, $round->roundNumber());
        $this->assertEquals(RoundStatus::Pending, $round->status());
    }

    public function test_pending_can_start(): void
    {
        $round = $this->createRound(RoundStatus::Pending);

        $round->start();

        $this->assertEquals(RoundStatus::InProgress, $round->status());
        $this->assertInstanceOf(DateTimeImmutable::class, $round->startedAt());
    }

    public function test_in_progress_can_complete(): void
    {
        $round = $this->createRound(RoundStatus::InProgress);

        $round->complete();

        $this->assertEquals(RoundStatus::Finished, $round->status());
        $this->assertInstanceOf(DateTimeImmutable::class, $round->completedAt());
    }

    public function test_pending_cannot_complete_directly(): void
    {
        $round = $this->createRound(RoundStatus::Pending);

        $this->expectException(InvalidStateTransitionException::class);

        $round->complete();
    }

    public function test_finished_cannot_start(): void
    {
        $round = $this->createRound(RoundStatus::Finished);

        $this->expectException(InvalidStateTransitionException::class);

        $round->start();
    }

    public function test_is_active_returns_correct_values(): void
    {
        $pending = $this->createRound(RoundStatus::Pending);
        $inProgress = $this->createRound(RoundStatus::InProgress);
        $finished = $this->createRound(RoundStatus::Finished);

        $this->assertFalse($pending->isActive());
        $this->assertTrue($inProgress->isActive());
        $this->assertFalse($finished->isActive());
    }

    public function test_is_finished_returns_correct_values(): void
    {
        $pending = $this->createRound(RoundStatus::Pending);
        $inProgress = $this->createRound(RoundStatus::InProgress);
        $finished = $this->createRound(RoundStatus::Finished);

        $this->assertFalse($pending->isFinished());
        $this->assertFalse($inProgress->isFinished());
        $this->assertTrue($finished->isFinished());
    }
}
