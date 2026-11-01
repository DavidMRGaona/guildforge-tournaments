<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use PHPUnit\Framework\TestCase;

final class MatchReportTest extends TestCase
{
    public function test_dispute_is_stored_and_correcting_clears_it(): void
    {
        $report = new MatchReport(
            matchId: 'match-1',
            result: MatchResult::PlayerOneWin,
            reportedById: 'user-1',
            reportedAt: new DateTimeImmutable('2030-05-01 10:00:00'),
            player1Score: 20,
            player2Score: 15,
        );

        $this->assertFalse($report->isDisputed());

        $report->dispute('user-2', 'We drew', new DateTimeImmutable('2030-05-01 10:05:00'));

        $this->assertTrue($report->isDisputed());
        $this->assertSame('user-2', $report->disputedById());
        $this->assertSame('We drew', $report->disputeReason());
        $this->assertEquals(new DateTimeImmutable('2030-05-01 10:05:00'), $report->disputedAt());

        $report->correct(MatchResult::Draw, 18, 18, ['vp' => 18], ['vp' => 18], new DateTimeImmutable('2030-05-01 10:10:00'));

        $this->assertFalse($report->isDisputed());
        $this->assertNull($report->disputedById());
        $this->assertNull($report->disputeReason());
        $this->assertNull($report->disputedAt());
        $this->assertSame(MatchResult::Draw, $report->result());
        $this->assertSame(18, $report->player1Score());
        $this->assertSame(18, $report->player2Score());
        $this->assertSame(['vp' => 18], $report->player1Stats());
        $this->assertSame(['vp' => 18], $report->player2Stats());
        $this->assertEquals(new DateTimeImmutable('2030-05-01 10:10:00'), $report->reportedAt());
        $this->assertSame('user-1', $report->reportedById());
    }
}
