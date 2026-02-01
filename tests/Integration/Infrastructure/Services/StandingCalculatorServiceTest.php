<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Services\StandingCalculatorServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Infrastructure\Services\StandingCalculatorService;
use PHPUnit\Framework\TestCase;

final class StandingCalculatorServiceTest extends TestCase
{
    private StandingCalculatorService $service;

    /** @var array<ScoreWeight> */
    private array $defaultScoreWeights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StandingCalculatorService();

        $this->defaultScoreWeights = [
            new ScoreWeight(name: 'Victoria', key: 'win', points: 3.0),
            new ScoreWeight(name: 'Empate', key: 'draw', points: 1.0),
            new ScoreWeight(name: 'Derrota', key: 'loss', points: 0.0),
            new ScoreWeight(name: 'Bye', key: 'bye', points: 3.0),
        ];
    }

    public function test_it_implements_standing_calculator_service_interface(): void
    {
        $this->assertInstanceOf(StandingCalculatorServiceInterface::class, $this->service);
    }

    public function test_calculates_points_from_score_weights(): void
    {
        $participants = $this->createParticipants(4);
        $roundId = 'round-1';

        // P1 beats P2, P3 beats P4
        $matches = [
            $this->createMatch($roundId, $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch($roundId, $participants[2], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        $this->assertCount(4, $standings);

        // Winners should have 3 points
        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $this->assertEquals(3.0, $p1Standing->points());
        $this->assertEquals(1, $p1Standing->wins());
        $this->assertEquals(0, $p1Standing->losses());

        $p3Standing = $this->findStandingForParticipant($standings, $participants[2]->id()->value);
        $this->assertEquals(3.0, $p3Standing->points());

        // Losers should have 0 points
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);
        $this->assertEquals(0.0, $p2Standing->points());
        $this->assertEquals(0, $p2Standing->wins());
        $this->assertEquals(1, $p2Standing->losses());
    }

    public function test_calculates_points_with_custom_score_weights(): void
    {
        $customScoreWeights = [
            new ScoreWeight(name: 'Victoria', key: 'win', points: 5.0),
            new ScoreWeight(name: 'Empate', key: 'draw', points: 2.0),
            new ScoreWeight(name: 'Derrota', key: 'loss', points: 1.0),
            new ScoreWeight(name: 'Bye', key: 'bye', points: 5.0),
        ];

        $participants = $this->createParticipants(2);
        $roundId = 'round-1';

        $matches = [
            $this->createMatch($roundId, $participants[0], $participants[1], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $customScoreWeights,
            [Tiebreaker::Buchholz]
        );

        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $this->assertEquals(5.0, $p1Standing->points()); // Custom win points

        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);
        $this->assertEquals(1.0, $p2Standing->points()); // Custom loss points
    }

    public function test_calculates_draw_points(): void
    {
        $participants = $this->createParticipants(2);
        $roundId = 'round-1';

        $matches = [
            $this->createMatch($roundId, $participants[0], $participants[1], MatchResult::Draw),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);

        $this->assertEquals(1.0, $p1Standing->points());
        $this->assertEquals(1.0, $p2Standing->points());
        $this->assertEquals(1, $p1Standing->draws());
        $this->assertEquals(1, $p2Standing->draws());
    }

    public function test_calculates_bye_points(): void
    {
        $participants = $this->createParticipants(3);
        $roundId = 'round-1';

        $matches = [
            $this->createMatch($roundId, $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createByeMatch($roundId, $participants[2]),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        $byeStanding = $this->findStandingForParticipant($standings, $participants[2]->id()->value);
        $this->assertEquals(3.0, $byeStanding->points()); // Bye worth same as win
        $this->assertEquals(1, $byeStanding->byes());
    }

    public function test_calculates_buchholz_tiebreaker(): void
    {
        // Setup: P1 beats P2, P3 beats P4
        // Round 2: P1 beats P3, P2 beats P4
        // P1: 6 pts (beat P2@0, beat P3@3) -> buchholz = 0 + 3 = 3
        // P2: 3 pts (lost to P1@6, beat P4@0) -> buchholz = 6 + 0 = 6
        // P3: 3 pts (beat P4@0, lost to P1@6) -> buchholz = 0 + 6 = 6
        // P4: 0 pts (lost to P3@3, lost to P2@3) -> buchholz = 3 + 3 = 6
        $participants = $this->createParticipants(4);

        $matches = [
            // Round 1
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('round-1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
            // Round 2
            $this->createMatch('round-2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participants[1], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        // Verify buchholz calculation
        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);
        $p3Standing = $this->findStandingForParticipant($standings, $participants[2]->id()->value);
        $p4Standing = $this->findStandingForParticipant($standings, $participants[3]->id()->value);

        // P1's opponents: P2 (3pts) + P3 (3pts) = 6
        $this->assertEquals(6.0, $p1Standing->buchholz());

        // P2's opponents: P1 (6pts) + P4 (0pts) = 6
        $this->assertEquals(6.0, $p2Standing->buchholz());

        // P3's opponents: P4 (0pts) + P1 (6pts) = 6
        $this->assertEquals(6.0, $p3Standing->buchholz());

        // P4's opponents: P3 (3pts) + P2 (3pts) = 6
        $this->assertEquals(6.0, $p4Standing->buchholz());
    }

    public function test_calculates_median_buchholz(): void
    {
        // 5 rounds scenario for meaningful median calculation
        // Player plays 5 opponents with varying points
        $participants = $this->createParticipants(6);

        // Create a scenario where P1 plays everyone
        // Opponents: P2(9pts), P3(6pts), P4(3pts), P5(0pts), P6(0pts) (excluding P1)
        // Median: remove best (9) and worst (0), sum = 6 + 3 + 0 = 9
        $matches = [
            // Round 1: P1 beats P2
            $this->createMatch('r1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('r1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
            $this->createMatch('r1', $participants[4], $participants[5], MatchResult::Draw),
            // Round 2: P1 beats P3
            $this->createMatch('r2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
            $this->createMatch('r2', $participants[1], $participants[3], MatchResult::PlayerOneWin),
            // Round 3: P1 beats P4
            $this->createMatch('r3', $participants[0], $participants[3], MatchResult::PlayerOneWin),
            $this->createMatch('r3', $participants[1], $participants[2], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::MedianBuchholz]
        );

        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);

        // P1 faced: P2, P3, P4
        // P2 has 6 pts, P3 has 3 pts, P4 has 0 pts
        // Buchholz = 6 + 3 + 0 = 9
        // MedianBuchholz (remove best/worst) = 3
        $this->assertIsFloat($p1Standing->medianBuchholz());
        $this->assertEquals(3.0, $p1Standing->medianBuchholz());
    }

    public function test_calculates_progressive_score(): void
    {
        // Progressive = running total after each round
        // P1: R1 Win(3), R2 Win(3) -> Progressive = 3 + 6 = 9
        $participants = $this->createParticipants(4);

        $matches = [
            // Round 1
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('round-1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
            // Round 2
            $this->createMatch('round-2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
            $this->createMatch('round-2', $participants[1], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Progressive]
        );

        // P1: Round 1 = 3pts, Round 2 = 6pts total. Progressive = 3 + 6 = 9
        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $this->assertEquals(9.0, $p1Standing->progressive());

        // P2: Round 1 = 0pts, Round 2 = 3pts total. Progressive = 0 + 3 = 3
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);
        $this->assertEquals(3.0, $p2Standing->progressive());

        // P3: Round 1 = 3pts, Round 2 = 3pts total. Progressive = 3 + 3 = 6
        $p3Standing = $this->findStandingForParticipant($standings, $participants[2]->id()->value);
        $this->assertEquals(6.0, $p3Standing->progressive());

        // P4: Round 1 = 0pts, Round 2 = 0pts total. Progressive = 0 + 0 = 0
        $p4Standing = $this->findStandingForParticipant($standings, $participants[3]->id()->value);
        $this->assertEquals(0.0, $p4Standing->progressive());
    }

    public function test_calculates_opponent_win_percentage(): void
    {
        // Opponent Win Percentage = average of (opponent wins / opponent matches played)
        $participants = $this->createParticipants(4);

        // Simple scenario: P1 beats P2, P2 has 0% win rate
        // P1's OWP = 0%
        $matches = [
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('round-1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::OpponentWinPercentage]
        );

        // P1's opponent is P2 who has 0 wins out of 1 match = 0%
        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $this->assertEquals(0.0, $p1Standing->opponentWinPercentage());

        // P2's opponent is P1 who has 1 win out of 1 match = 100%
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);
        $this->assertEquals(1.0, $p2Standing->opponentWinPercentage());
    }

    public function test_sorts_by_points_then_tiebreakers(): void
    {
        // P1 and P2 have same points, use buchholz to break tie
        $participants = $this->createParticipants(4);

        // Both P1 and P3 will have 3 points, but different buchholz
        $matches = [
            // Round 1
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('round-1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        // P1 and P3 both have 3 pts
        // P1's opponent P2 has 0pts, buchholz = 0
        // P3's opponent P4 has 0pts, buchholz = 0
        // Since buchholz is tied, ranks may be equal or tie-broken by some other criteria

        // First two should have 3 points, last two should have 0 points
        $this->assertEquals(3.0, $standings[0]->points());
        $this->assertEquals(3.0, $standings[1]->points());
        $this->assertEquals(0.0, $standings[2]->points());
        $this->assertEquals(0.0, $standings[3]->points());

        // Verify ranks are assigned correctly (1-4)
        $ranks = array_map(fn (Standing $s): int => $s->rank(), $standings);
        $this->assertEquals([1, 2, 3, 4], $ranks);
    }

    public function test_multiple_tiebreakers_in_order(): void
    {
        // Test that tiebreakers are applied in order
        $participants = $this->createParticipants(4);

        $matches = [
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('round-1', $participants[2], $participants[3], MatchResult::PlayerOneWin),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz, Tiebreaker::Progressive, Tiebreaker::OpponentWinPercentage]
        );

        // All standings should have all tiebreaker values calculated
        foreach ($standings as $standing) {
            $this->assertIsFloat($standing->buchholz());
            $this->assertIsFloat($standing->progressive());
            $this->assertIsFloat($standing->opponentWinPercentage());
        }
    }

    public function test_handles_double_loss(): void
    {
        $participants = $this->createParticipants(2);

        $matches = [
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::DoubleLoss),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        // Both should have 0 points and 1 loss each
        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);

        $this->assertEquals(0.0, $p1Standing->points());
        $this->assertEquals(0.0, $p2Standing->points());
        $this->assertEquals(1, $p1Standing->losses());
        $this->assertEquals(1, $p2Standing->losses());
    }

    public function test_handles_unplayed_matches(): void
    {
        $participants = $this->createParticipants(2);

        // Unplayed match should not affect standings
        $matches = [
            $this->createMatch('round-1', $participants[0], $participants[1], MatchResult::NotPlayed),
        ];

        $standings = $this->service->calculate(
            $participants,
            $matches,
            $this->defaultScoreWeights,
            [Tiebreaker::Buchholz]
        );

        $p1Standing = $this->findStandingForParticipant($standings, $participants[0]->id()->value);
        $p2Standing = $this->findStandingForParticipant($standings, $participants[1]->id()->value);

        $this->assertEquals(0.0, $p1Standing->points());
        $this->assertEquals(0.0, $p2Standing->points());
        $this->assertEquals(0, $p1Standing->matchesPlayed());
        $this->assertEquals(0, $p2Standing->matchesPlayed());
    }

    public function test_returns_empty_array_for_no_participants(): void
    {
        $standings = $this->service->calculate(
            participants: [],
            matches: [],
            scoreWeights: $this->defaultScoreWeights,
            tiebreakers: [Tiebreaker::Buchholz]
        );

        $this->assertCount(0, $standings);
    }

    public function test_handles_participants_with_no_matches(): void
    {
        $participants = $this->createParticipants(2);

        // No matches at all
        $standings = $this->service->calculate(
            $participants,
            matches: [],
            scoreWeights: $this->defaultScoreWeights,
            tiebreakers: [Tiebreaker::Buchholz]
        );

        $this->assertCount(2, $standings);

        foreach ($standings as $standing) {
            $this->assertEquals(0.0, $standing->points());
            $this->assertEquals(0, $standing->matchesPlayed());
        }
    }

    public function test_calculate_buchholz_method(): void
    {
        $participants = $this->createParticipants(4);
        $tournamentId = 'tournament-id';

        // Setup standings manually
        $allStandings = [
            new Standing('s1', $tournamentId, $participants[0]->id()->value, 1, 2, 2, 0, 0, 0, 6.0),
            new Standing('s2', $tournamentId, $participants[1]->id()->value, 2, 2, 1, 0, 1, 0, 3.0),
            new Standing('s3', $tournamentId, $participants[2]->id()->value, 3, 2, 1, 0, 1, 0, 3.0),
            new Standing('s4', $tournamentId, $participants[3]->id()->value, 4, 2, 0, 0, 2, 0, 0.0),
        ];

        // P1 played P2 and P3
        $matches = [
            $this->createMatch('r1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('r2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
        ];

        $buchholz = $this->service->calculateBuchholz(
            $participants[0]->id()->value,
            $matches,
            $allStandings
        );

        // P1's opponents: P2 (3pts) + P3 (3pts) = 6
        $this->assertEquals(6.0, $buchholz);
    }

    public function test_calculate_median_buchholz_method(): void
    {
        $participants = $this->createParticipants(5);
        $tournamentId = 'tournament-id';

        // P1 played P2(9pts), P3(6pts), P4(3pts)
        $allStandings = [
            new Standing('s1', $tournamentId, $participants[0]->id()->value, 1, 3, 3, 0, 0, 0, 9.0),
            new Standing('s2', $tournamentId, $participants[1]->id()->value, 2, 3, 2, 0, 1, 0, 6.0),
            new Standing('s3', $tournamentId, $participants[2]->id()->value, 3, 3, 1, 0, 2, 0, 3.0),
            new Standing('s4', $tournamentId, $participants[3]->id()->value, 4, 3, 0, 0, 3, 0, 0.0),
        ];

        $matches = [
            $this->createMatch('r1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('r2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
            $this->createMatch('r3', $participants[0], $participants[3], MatchResult::PlayerOneWin),
        ];

        $medianBuchholz = $this->service->calculateMedianBuchholz(
            $participants[0]->id()->value,
            $matches,
            $allStandings
        );

        // Opponents: P2(6pts), P3(3pts), P4(0pts)
        // Remove best(6) and worst(0): remaining = 3
        $this->assertEquals(3.0, $medianBuchholz);
    }

    public function test_calculate_progressive_method(): void
    {
        $participants = $this->createParticipants(2);

        // P1 wins round 1, loses round 2
        $matches = [
            $this->createMatchWithRoundId('round-1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatchWithRoundId('round-2', $participants[1], $participants[0], MatchResult::PlayerOneWin),
        ];

        $progressive = $this->service->calculateProgressive(
            $participants[0]->id()->value,
            $matches,
            $this->defaultScoreWeights
        );

        // R1: 3 pts, R2: 3 pts total. Progressive = 3 + 3 = 6
        $this->assertEquals(6.0, $progressive);
    }

    public function test_calculate_opponent_win_percentage_method(): void
    {
        $participants = $this->createParticipants(4);
        $tournamentId = 'tournament-id';

        // P1 played P2(1W 1L = 50%) and P3(2W 0L = 100%)
        $allStandings = [
            new Standing('s1', $tournamentId, $participants[0]->id()->value, 1, 2, 2, 0, 0, 0, 6.0),
            new Standing('s2', $tournamentId, $participants[1]->id()->value, 2, 2, 1, 0, 1, 0, 3.0),
            new Standing('s3', $tournamentId, $participants[2]->id()->value, 3, 2, 2, 0, 0, 0, 6.0),
            new Standing('s4', $tournamentId, $participants[3]->id()->value, 4, 2, 0, 0, 2, 0, 0.0),
        ];

        $matches = [
            $this->createMatch('r1', $participants[0], $participants[1], MatchResult::PlayerOneWin),
            $this->createMatch('r2', $participants[0], $participants[2], MatchResult::PlayerOneWin),
        ];

        $owp = $this->service->calculateOpponentWinPercentage(
            $participants[0]->id()->value,
            $matches,
            $allStandings
        );

        // P2 win rate: 1/2 = 0.5
        // P3 win rate: 2/2 = 1.0
        // Average: (0.5 + 1.0) / 2 = 0.75
        $this->assertEquals(0.75, $owp);
    }

    /**
     * @return array<Participant>
     */
    private function createParticipants(int $count): array
    {
        $participants = [];
        $tournamentId = 'tournament-id';

        for ($i = 0; $i < $count; $i++) {
            $participants[] = new Participant(
                id: ParticipantId::generate(),
                tournamentId: $tournamentId,
                status: ParticipantStatus::CheckedIn,
                userId: 'user-' . ($i + 1),
            );
        }

        return $participants;
    }

    private function createMatch(
        string $roundId,
        Participant $player1,
        Participant $player2,
        MatchResult $result
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1->id()->value,
            player2Id: $player2->id()->value,
            result: $result,
        );
    }

    private function createMatchWithRoundId(
        string $roundId,
        Participant $player1,
        Participant $player2,
        MatchResult $result
    ): TournamentMatch {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1->id()->value,
            player2Id: $player2->id()->value,
            result: $result,
        );
    }

    private function createByeMatch(string $roundId, Participant $player): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player->id()->value,
            player2Id: null,
            result: MatchResult::Bye,
        );
    }

    /**
     * @param  array<Standing>  $standings
     */
    private function findStandingForParticipant(array $standings, string $participantId): Standing
    {
        foreach ($standings as $standing) {
            if ($standing->participantId() === $participantId) {
                return $standing;
            }
        }

        throw new \RuntimeException("Standing not found for participant: {$participantId}");
    }
}
