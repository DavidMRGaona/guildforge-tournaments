<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs\Response;

use Modules\Tournaments\Application\DTOs\Response\StandingsResponseDTO;
use PHPUnit\Framework\TestCase;

final class StandingsResponseDTOTest extends TestCase
{
    public function test_can_create_dto(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament-123',
            participantId: 'participant-456',
            participantName: 'John Doe',
            rank: 1,
            matchesPlayed: 5,
            wins: 4,
            draws: 1,
            losses: 0,
            byes: 0,
            points: 13.0,
            buchholz: 25.5,
            medianBuchholz: 20.0,
            progressive: 45.0,
            opponentWinPercentage: 0.75,
        );

        $this->assertEquals('tournament-123', $dto->tournamentId);
        $this->assertEquals('participant-456', $dto->participantId);
        $this->assertEquals('John Doe', $dto->participantName);
        $this->assertEquals(1, $dto->rank);
        $this->assertEquals(5, $dto->matchesPlayed);
        $this->assertEquals(4, $dto->wins);
        $this->assertEquals(1, $dto->draws);
        $this->assertEquals(0, $dto->losses);
        $this->assertEquals(0, $dto->byes);
        $this->assertEquals(13.0, $dto->points);
        $this->assertEquals(25.5, $dto->buchholz);
        $this->assertEquals(20.0, $dto->medianBuchholz);
        $this->assertEquals(45.0, $dto->progressive);
        $this->assertEquals(0.75, $dto->opponentWinPercentage);
    }

    public function test_can_create_dto_with_zero_values(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament',
            participantId: 'participant',
            participantName: 'New Player',
            rank: 16,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
            buchholz: 0.0,
            medianBuchholz: 0.0,
            progressive: 0.0,
            opponentWinPercentage: 0.0,
        );

        $this->assertEquals(0, $dto->matchesPlayed);
        $this->assertEquals(0.0, $dto->points);
        $this->assertEquals(0.0, $dto->buchholz);
    }

    public function test_win_percentage_calculation(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament',
            participantId: 'participant',
            participantName: 'Player',
            rank: 5,
            matchesPlayed: 10,
            wins: 7,
            draws: 2,
            losses: 1,
            byes: 0,
            points: 23.0,
            buchholz: 30.0,
            medianBuchholz: 25.0,
            progressive: 50.0,
            opponentWinPercentage: 0.65,
        );

        $this->assertEquals(70.0, $dto->winPercentage());
    }

    public function test_win_percentage_returns_zero_when_no_matches(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament',
            participantId: 'participant',
            participantName: 'Player',
            rank: 1,
            matchesPlayed: 0,
            wins: 0,
            draws: 0,
            losses: 0,
            byes: 0,
            points: 0.0,
            buchholz: 0.0,
            medianBuchholz: 0.0,
            progressive: 0.0,
            opponentWinPercentage: 0.0,
        );

        $this->assertEquals(0.0, $dto->winPercentage());
    }

    public function test_draw_percentage_calculation(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament',
            participantId: 'participant',
            participantName: 'Player',
            rank: 3,
            matchesPlayed: 8,
            wins: 4,
            draws: 2,
            losses: 2,
            byes: 0,
            points: 14.0,
            buchholz: 20.0,
            medianBuchholz: 15.0,
            progressive: 35.0,
            opponentWinPercentage: 0.55,
        );

        $this->assertEquals(25.0, $dto->drawPercentage());
    }

    public function test_loss_percentage_calculation(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament',
            participantId: 'participant',
            participantName: 'Player',
            rank: 10,
            matchesPlayed: 5,
            wins: 1,
            draws: 1,
            losses: 3,
            byes: 0,
            points: 4.0,
            buchholz: 15.0,
            medianBuchholz: 12.0,
            progressive: 10.0,
            opponentWinPercentage: 0.80,
        );

        $this->assertEquals(60.0, $dto->lossPercentage());
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new StandingsResponseDTO(
            tournamentId: 'tournament-array',
            participantId: 'participant-array',
            participantName: 'Array Player',
            rank: 2,
            matchesPlayed: 6,
            wins: 5,
            draws: 0,
            losses: 1,
            byes: 1,
            points: 18.0,
            buchholz: 28.5,
            medianBuchholz: 22.0,
            progressive: 55.0,
            opponentWinPercentage: 0.68,
        );

        $array = $dto->toArray();

        $this->assertEquals('tournament-array', $array['tournament_id']);
        $this->assertEquals('participant-array', $array['participant_id']);
        $this->assertEquals('Array Player', $array['participant_name']);
        $this->assertEquals(2, $array['rank']);
        $this->assertEquals(6, $array['matches_played']);
        $this->assertEquals(5, $array['wins']);
        $this->assertEquals(0, $array['draws']);
        $this->assertEquals(1, $array['losses']);
        $this->assertEquals(1, $array['byes']);
        $this->assertEquals(18.0, $array['points']);
        $this->assertEquals(28.5, $array['buchholz']);
        $this->assertEquals(22.0, $array['median_buchholz']);
        $this->assertEquals(55.0, $array['progressive']);
        $this->assertEquals(0.68, $array['opponent_win_percentage']);
        $this->assertArrayHasKey('win_percentage', $array);
        $this->assertArrayHasKey('draw_percentage', $array);
        $this->assertArrayHasKey('loss_percentage', $array);
    }
}
