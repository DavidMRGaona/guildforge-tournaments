<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use Modules\Tournaments\Domain\Entities\Standing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class StandingTest extends TestCase
{
    public function test_it_creates_standing_with_all_fields(): void
    {
        $tournamentId = Uuid::uuid4()->toString();
        $participantId = Uuid::uuid4()->toString();

        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: $tournamentId,
            participantId: $participantId,
            rank: 1,
            matchesPlayed: 5,
            wins: 4,
            draws: 0,
            losses: 1,
            byes: 0,
            points: 12.0,
            buchholz: 15.0,
            medianBuchholz: 12.0,
            progressive: 8.0,
            opponentWinPercentage: 0.75,
        );

        $this->assertEquals($tournamentId, $standing->tournamentId());
        $this->assertEquals($participantId, $standing->participantId());
        $this->assertEquals(1, $standing->rank());
        $this->assertEquals(5, $standing->matchesPlayed());
        $this->assertEquals(4, $standing->wins());
        $this->assertEquals(0, $standing->draws());
        $this->assertEquals(1, $standing->losses());
        $this->assertEquals(0, $standing->byes());
        $this->assertEquals(12.0, $standing->points());
        $this->assertEquals(15.0, $standing->buchholz());
        $this->assertEquals(12.0, $standing->medianBuchholz());
        $this->assertEquals(8.0, $standing->progressive());
        $this->assertEquals(0.75, $standing->opponentWinPercentage());
    }

    public function test_tiebreaker_values_default_to_zero(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
        );

        $this->assertEquals(0.0, $standing->buchholz());
        $this->assertEquals(0.0, $standing->medianBuchholz());
        $this->assertEquals(0.0, $standing->progressive());
        $this->assertEquals(0.0, $standing->opponentWinPercentage());
    }

    public function test_update_tiebreakers(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 3,
            wins: 2,
            draws: 1,
            losses: 0,
            byes: 0,
            points: 7.0,
        );

        $standing->updateTiebreakers(
            buchholz: 10.5,
            medianBuchholz: 8.0,
            progressive: 5.0,
            opponentWinPercentage: 0.65
        );

        $this->assertEquals(10.5, $standing->buchholz());
        $this->assertEquals(8.0, $standing->medianBuchholz());
        $this->assertEquals(5.0, $standing->progressive());
        $this->assertEquals(0.65, $standing->opponentWinPercentage());
    }

    public function test_update_rank(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 3,
            wins: 2,
            draws: 1,
            losses: 0,
            byes: 0,
            points: 7.0,
        );

        $standing->updateRank(3);

        $this->assertEquals(3, $standing->rank());
    }

    public function test_record_win(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
        );

        $standing->recordWin(3.0);

        $this->assertEquals(1, $standing->matchesPlayed());
        $this->assertEquals(1, $standing->wins());
        $this->assertEquals(3.0, $standing->points());
    }

    public function test_record_draw(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
        );

        $standing->recordDraw(1.0);

        $this->assertEquals(1, $standing->matchesPlayed());
        $this->assertEquals(1, $standing->draws());
        $this->assertEquals(1.0, $standing->points());
    }

    public function test_record_loss(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
        );

        $standing->recordLoss(0.0);

        $this->assertEquals(1, $standing->matchesPlayed());
        $this->assertEquals(1, $standing->losses());
        $this->assertEquals(0.0, $standing->points());
    }

    public function test_record_bye(): void
    {
        $standing = new Standing(
            id: Uuid::uuid4()->toString(),
            tournamentId: Uuid::uuid4()->toString(),
            participantId: Uuid::uuid4()->toString(),
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
        );

        $standing->recordBye(3.0);

        $this->assertEquals(1, $standing->matchesPlayed());
        $this->assertEquals(1, $standing->byes());
        $this->assertEquals(3.0, $standing->points());
    }
}
