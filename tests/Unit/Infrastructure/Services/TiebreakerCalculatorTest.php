<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Services\TiebreakerCalculatorInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Services\TiebreakerCalculator;
use PHPUnit\Framework\TestCase;

final class TiebreakerCalculatorTest extends TestCase
{
    private TiebreakerCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new TiebreakerCalculator();
    }

    public function test_it_implements_tiebreaker_calculator_interface(): void
    {
        $this->assertInstanceOf(TiebreakerCalculatorInterface::class, $this->calculator);
    }

    public function test_it_calculates_buchholz_sum_of_opponent_points(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';
        $opponent2Id = 'player-3';

        // Player 1 faced Player 2 (6 points) and Player 3 (3 points)
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participantId, $opponent2Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 9.0, 2),
            $this->createStanding($opponent1Id, 6.0, 2),
            $this->createStanding($opponent2Id, 3.0, 2),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Buchholz should be 6.0 + 3.0 = 9.0
        $this->assertArrayHasKey('buchholz', $result);
        $this->assertEquals(9.0, $result['buchholz']);
    }

    public function test_it_calculates_median_buchholz_excluding_best_and_worst(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';
        $opponent2Id = 'player-3';
        $opponent3Id = 'player-4';
        $opponent4Id = 'player-5';

        // Player 1 faced 4 opponents with points: 12, 9, 6, 3
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participantId, $opponent2Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-3', $participantId, $opponent3Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-4', $participantId, $opponent4Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 15.0, 4),
            $this->createStanding($opponent1Id, 12.0, 4),
            $this->createStanding($opponent2Id, 9.0, 4),
            $this->createStanding($opponent3Id, 6.0, 4),
            $this->createStanding($opponent4Id, 3.0, 4),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('median_buchholz', 'Median Buchholz', TiebreakerType::MedianBuchholz),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Remove best (12) and worst (3), sum remaining: 9 + 6 = 15
        $this->assertArrayHasKey('median_buchholz', $result);
        $this->assertEquals(15.0, $result['median_buchholz']);
    }

    public function test_it_calculates_median_buchholz_with_less_than_3_opponents(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';

        // Only 1 opponent - should return same as Buchholz
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 3.0, 1),
            $this->createStanding($opponent1Id, 6.0, 1),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('median_buchholz', 'Median Buchholz', TiebreakerType::MedianBuchholz),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // With only 1 opponent, can't remove best/worst, so return full sum
        $this->assertArrayHasKey('median_buchholz', $result);
        $this->assertEquals(6.0, $result['median_buchholz']);
    }

    public function test_it_calculates_progressive_cumulative_points_per_round(): void
    {
        $participantId = 'player-1';

        // Round 1: Win (3 pts), Total = 3, Cumulative = 3
        // Round 2: Win (3 pts), Total = 6, Cumulative = 3 + 6 = 9
        // Round 3: Loss (0 pts), Total = 6, Cumulative = 9 + 6 = 15
        $matches = [
            $this->createMatch('round-1', $participantId, 'player-2', MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participantId, 'player-3', MatchResult::PlayerOneWin),
            $this->createMatch('round-3', $participantId, 'player-4', MatchResult::PlayerTwoWin),
        ];

        $standings = [
            $this->createStanding($participantId, 6.0, 3, 2, 0, 1),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('progressive', 'Progressive', TiebreakerType::Progressive),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Progressive = 3 + 6 + 6 = 15
        $this->assertArrayHasKey('progressive', $result);
        $this->assertEquals(15.0, $result['progressive']);
    }

    public function test_it_calculates_opponent_win_percentage(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';
        $opponent2Id = 'player-3';

        // Player 1 faced:
        // - Player 2: 2 wins out of 3 matches = 2/3 = 0.6667
        // - Player 3: 1 win out of 2 matches = 1/2 = 0.5
        // Average: (0.6667 + 0.5) / 2 = 0.5833...
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participantId, $opponent2Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 6.0, 2, 2, 0, 0),
            $this->createStanding($opponent1Id, 6.0, 3, 2, 0, 1),
            $this->createStanding($opponent2Id, 3.0, 2, 1, 0, 1),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('owp', 'Opponent Win %', TiebreakerType::OpponentWinPercentage),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('owp', $result);
        $this->assertEqualsWithDelta(0.5833, $result['owp'], 0.0001);
    }

    public function test_it_calculates_stat_sum_total_of_stat_across_matches(): void
    {
        $participantId = 'player-1';

        // Player 1 scored 15 points in match 1, 20 in match 2
        $matches = [
            $this->createMatchWithStats(
                'round-1',
                $participantId,
                'player-2',
                MatchResult::PlayerOneWin,
                ['points_painted' => 15, 'units_killed' => 5],
                ['points_painted' => 10, 'units_killed' => 3]
            ),
            $this->createMatchWithStats(
                'round-2',
                $participantId,
                'player-3',
                MatchResult::PlayerOneWin,
                ['points_painted' => 20, 'units_killed' => 7],
                ['points_painted' => 12, 'units_killed' => 4]
            ),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'total_points',
                'Total Points Painted',
                TiebreakerType::StatSum,
                stat: 'points_painted'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // StatSum should be 15 + 20 = 35
        $this->assertArrayHasKey('total_points', $result);
        $this->assertEquals(35.0, $result['total_points']);
    }

    public function test_it_calculates_stat_diff_own_minus_opponent_stat(): void
    {
        $participantId = 'player-1';

        // Match 1: Player 1 killed 5, opponent killed 3 = +2
        // Match 2: Player 1 killed 7, opponent killed 4 = +3
        // Total diff: 2 + 3 = 5
        $matches = [
            $this->createMatchWithStats(
                'round-1',
                $participantId,
                'player-2',
                MatchResult::PlayerOneWin,
                ['units_killed' => 5],
                ['units_killed' => 3]
            ),
            $this->createMatchWithStats(
                'round-2',
                $participantId,
                'player-3',
                MatchResult::PlayerOneWin,
                ['units_killed' => 7],
                ['units_killed' => 4]
            ),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'kill_diff',
                'Unit Kill Differential',
                TiebreakerType::StatDiff,
                stat: 'units_killed'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('kill_diff', $result);
        $this->assertEquals(5.0, $result['kill_diff']);
    }

    public function test_it_calculates_stat_average_mean_of_stat(): void
    {
        $participantId = 'player-1';

        // Player 1 scored 15, 20, 18 across 3 matches
        // Average: (15 + 20 + 18) / 3 = 17.6667
        $matches = [
            $this->createMatchWithStats(
                'round-1',
                $participantId,
                'player-2',
                MatchResult::PlayerOneWin,
                ['points_painted' => 15],
                ['points_painted' => 10]
            ),
            $this->createMatchWithStats(
                'round-2',
                $participantId,
                'player-3',
                MatchResult::PlayerOneWin,
                ['points_painted' => 20],
                ['points_painted' => 12]
            ),
            $this->createMatchWithStats(
                'round-3',
                $participantId,
                'player-4',
                MatchResult::PlayerOneWin,
                ['points_painted' => 18],
                ['points_painted' => 14]
            ),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'avg_points',
                'Average Points Painted',
                TiebreakerType::StatAverage,
                stat: 'points_painted'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('avg_points', $result);
        $this->assertEqualsWithDelta(17.6667, $result['avg_points'], 0.0001);
    }

    public function test_it_calculates_stat_max_best_single_match_value(): void
    {
        $participantId = 'player-1';

        // Player 1 scored 15, 25, 18 across 3 matches
        // Max: 25
        $matches = [
            $this->createMatchWithStats(
                'round-1',
                $participantId,
                'player-2',
                MatchResult::PlayerOneWin,
                ['points_painted' => 15],
                ['points_painted' => 10]
            ),
            $this->createMatchWithStats(
                'round-2',
                $participantId,
                'player-3',
                MatchResult::PlayerOneWin,
                ['points_painted' => 25],
                ['points_painted' => 12]
            ),
            $this->createMatchWithStats(
                'round-3',
                $participantId,
                'player-4',
                MatchResult::PlayerOneWin,
                ['points_painted' => 18],
                ['points_painted' => 14]
            ),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'max_points',
                'Max Points Painted',
                TiebreakerType::StatMax,
                stat: 'points_painted'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('max_points', $result);
        $this->assertEquals(25.0, $result['max_points']);
    }

    public function test_it_calculates_strength_of_schedule_average_opponent_points(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';
        $opponent2Id = 'player-3';
        $opponent3Id = 'player-4';

        // Player 1 faced opponents with 12, 9, 6 points
        // SOS = (12 + 9 + 6) / 3 = 9.0
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participantId, $opponent2Id, MatchResult::PlayerOneWin),
            $this->createMatch('round-3', $participantId, $opponent3Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 15.0, 3),
            $this->createStanding($opponent1Id, 12.0, 3),
            $this->createStanding($opponent2Id, 9.0, 3),
            $this->createStanding($opponent3Id, 6.0, 3),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('sos', 'Strength of Schedule', TiebreakerType::StrengthOfSchedule),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('sos', $result);
        $this->assertEquals(9.0, $result['sos']);
    }

    public function test_it_calculates_margin_of_victory_sum_of_positive_margins(): void
    {
        $participantId = 'player-1';

        // Match 1: Won 20-15 = +5
        // Match 2: Won 18-18 = 0
        // Match 3: Lost 10-15 = -5 (not counted)
        // MOV = 5 + 0 = 5
        $matches = [
            $this->createMatchWithScores('round-1', $participantId, 'player-2', MatchResult::PlayerOneWin, 20, 15),
            $this->createMatchWithScores('round-2', $participantId, 'player-3', MatchResult::Draw, 18, 18),
            $this->createMatchWithScores('round-3', $participantId, 'player-4', MatchResult::PlayerTwoWin, 10, 15),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition('mov', 'Margin of Victory', TiebreakerType::MarginOfVictory),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertArrayHasKey('mov', $result);
        $this->assertEquals(5.0, $result['mov']);
    }

    public function test_it_applies_min_value_floor(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';

        // Player 1 faced opponent with 0 wins out of 2 matches = 0.0
        // But minValue is 0.33, so OWP should be 0.33
        $matches = [
            $this->createMatch('round-1', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 3.0, 1, 1, 0, 0),
            $this->createStanding($opponent1Id, 0.0, 2, 0, 0, 2),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'owp',
                'Opponent Win %',
                TiebreakerType::OpponentWinPercentage,
                minValue: 0.33
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Without minValue, OWP would be 0.0, but with minValue it's 0.33
        $this->assertArrayHasKey('owp', $result);
        $this->assertEquals(0.33, $result['owp']);
    }

    public function test_it_calculates_multiple_tiebreakers_in_order(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';
        $opponent2Id = 'player-3';

        $matches = [
            $this->createMatchWithStats(
                'round-1',
                $participantId,
                $opponent1Id,
                MatchResult::PlayerOneWin,
                ['points_painted' => 15],
                ['points_painted' => 10]
            ),
            $this->createMatchWithStats(
                'round-2',
                $participantId,
                $opponent2Id,
                MatchResult::PlayerOneWin,
                ['points_painted' => 20],
                ['points_painted' => 12]
            ),
        ];

        $standings = [
            $this->createStanding($participantId, 6.0, 2, 2, 0, 0),
            $this->createStanding($opponent1Id, 3.0, 2, 1, 0, 1),
            $this->createStanding($opponent2Id, 0.0, 2, 0, 0, 2),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
            new TiebreakerDefinition('progressive', 'Progressive', TiebreakerType::Progressive),
            new TiebreakerDefinition('owp', 'OWP', TiebreakerType::OpponentWinPercentage),
            new TiebreakerDefinition(
                'total_painted',
                'Total Painted',
                TiebreakerType::StatSum,
                stat: 'points_painted'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // All tiebreakers should be calculated
        $this->assertArrayHasKey('buchholz', $result);
        $this->assertArrayHasKey('progressive', $result);
        $this->assertArrayHasKey('owp', $result);
        $this->assertArrayHasKey('total_painted', $result);

        // Verify values
        $this->assertEquals(3.0, $result['buchholz']); // 3 + 0
        $this->assertEquals(9.0, $result['progressive']); // 3 + 6
        $this->assertEquals(0.25, $result['owp']); // (0.5 + 0.0) / 2
        $this->assertEquals(35.0, $result['total_painted']); // 15 + 20
    }

    public function test_it_returns_empty_array_when_no_tiebreakers_configured(): void
    {
        $participantId = 'player-1';
        $matches = [];
        $standings = [];
        $tiebreakerConfig = [];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_it_handles_participant_with_no_matches(): void
    {
        $participantId = 'player-1';

        $tiebreakerConfig = [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
            new TiebreakerDefinition('progressive', 'Progressive', TiebreakerType::Progressive),
        ];

        $result = $this->calculator->calculate($participantId, [], [], $tiebreakerConfig);

        // All tiebreakers should return 0
        $this->assertEquals(0.0, $result['buchholz']);
        $this->assertEquals(0.0, $result['progressive']);
    }

    public function test_it_skips_bye_matches_for_opponent_based_tiebreakers(): void
    {
        $participantId = 'player-1';
        $opponent1Id = 'player-2';

        // Player 1 had 1 bye and 1 real match
        $matches = [
            $this->createByeMatch('round-1', $participantId),
            $this->createMatch('round-2', $participantId, $opponent1Id, MatchResult::PlayerOneWin),
        ];

        $standings = [
            $this->createStanding($participantId, 6.0, 2, 2, 0, 0, 1),
            $this->createStanding($opponent1Id, 3.0, 2, 1, 0, 1, 0),
        ];

        $tiebreakerConfig = [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
            new TiebreakerDefinition('owp', 'OWP', TiebreakerType::OpponentWinPercentage),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Buchholz should only count real opponent (player-2 with 3 points)
        $this->assertEquals(3.0, $result['buchholz']);

        // OWP should only count real opponent (50% win rate)
        $this->assertEquals(0.5, $result['owp']);
    }

    public function test_it_handles_missing_stats_gracefully(): void
    {
        $participantId = 'player-1';

        // Match has no stats
        $matches = [
            $this->createMatch('round-1', $participantId, 'player-2', MatchResult::PlayerOneWin),
        ];

        $standings = [];

        $tiebreakerConfig = [
            new TiebreakerDefinition(
                'total_painted',
                'Total Painted',
                TiebreakerType::StatSum,
                stat: 'points_painted'
            ),
        ];

        $result = $this->calculator->calculate($participantId, $matches, $standings, $tiebreakerConfig);

        // Should return 0 when stat is missing
        $this->assertEquals(0.0, $result['total_painted']);
    }

    private function createStanding(
        string $participantId,
        float $points,
        int $matchesPlayed,
        int $wins = 0,
        int $draws = 0,
        int $losses = 0,
        int $byes = 0
    ): Standing {
        return new Standing(
            id: 'standing-' . $participantId,
            tournamentId: 'tournament-1',
            participantId: $participantId,
            rank: 1,
            matchesPlayed: $matchesPlayed,
            wins: $wins,
            draws: $draws,
            losses: $losses,
            byes: $byes,
            points: $points,
        );
    }

    private function createMatch(
        string $roundId,
        string $player1Id,
        string $player2Id,
        MatchResult $result
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1Id,
            player2Id: $player2Id,
            result: $result,
        );
    }

    private function createByeMatch(string $roundId, string $playerId): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $playerId,
            player2Id: null,
            result: MatchResult::Bye,
        );
    }

    private function createMatchWithStats(
        string $roundId,
        string $player1Id,
        string $player2Id,
        MatchResult $result,
        array $player1Stats,
        array $player2Stats
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1Id,
            player2Id: $player2Id,
            result: $result,
            player1Score: null,
            player2Score: null,
            player1Stats: $player1Stats,
            player2Stats: $player2Stats,
        );
    }

    private function createMatchWithScores(
        string $roundId,
        string $player1Id,
        string $player2Id,
        MatchResult $result,
        int $player1Score,
        int $player2Score
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1Id,
            player2Id: $player2Id,
            result: $result,
            player1Score: $player1Score,
            player2Score: $player2Score,
        );
    }
}
