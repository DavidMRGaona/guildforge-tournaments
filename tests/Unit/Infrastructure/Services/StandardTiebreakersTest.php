<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerContext;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Services\TiebreakerCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The Swiss tiebreakers that used to be worth 0, and progressive with the real points and round order.
 *
 * Fixture (win 3, draw 1, loss 0):
 * round 1: A beats B 2-1, C draws D 1-1; round 2: A beats C 2-0, B beats D 2-1.
 * Points: A 6, B 3, C 1, D 1. Match win rate: A 1, B 0.5, C 0, D 0.
 */
final class StandardTiebreakersTest extends TestCase
{
    private TiebreakerCalculator $calculator;

    /** @var array<TournamentMatch> */
    private array $matches;

    /** @var array<Standing> */
    private array $standings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new TiebreakerCalculator();
        $this->matches = [
            $this->match('round-1', 'A', 'B', MatchResult::PlayerOneWin, 2, 1),
            $this->match('round-1', 'C', 'D', MatchResult::Draw, 1, 1),
            $this->match('round-2', 'A', 'C', MatchResult::PlayerOneWin, 2, 0),
            $this->match('round-2', 'B', 'D', MatchResult::PlayerOneWin, 2, 1),
        ];
        $this->standings = [
            $this->standing('A', 6.0, wins: 2),
            $this->standing('B', 3.0, wins: 1, losses: 1),
            $this->standing('C', 1.0, draws: 1, losses: 1),
            $this->standing('D', 1.0, draws: 1, losses: 1),
        ];
    }

    public function test_opponents_opponents_win_percentage_averages_the_opponents_owp(): void
    {
        // OWP: A 0.25, B 0.5, C 0.5, D 0.25
        $this->assertEqualsWithDelta(0.5, $this->value('A', TiebreakerType::OpponentOpponentWinPercentage), 0.0001);
        $this->assertEqualsWithDelta(0.25, $this->value('C', TiebreakerType::OpponentOpponentWinPercentage), 0.0001);
    }

    public function test_game_win_percentage_counts_games_from_the_match_scores(): void
    {
        $this->assertEqualsWithDelta(0.8, $this->value('A', TiebreakerType::GameWinPercentage), 0.0001);
        $this->assertEqualsWithDelta(0.5, $this->value('B', TiebreakerType::GameWinPercentage), 0.0001);
        $this->assertEqualsWithDelta(0.25, $this->value('C', TiebreakerType::GameWinPercentage), 0.0001);
        $this->assertEqualsWithDelta(0.4, $this->value('D', TiebreakerType::GameWinPercentage), 0.0001);
    }

    public function test_game_win_percentage_counts_a_match_without_scores_as_one_game(): void
    {
        $matches = [
            $this->match('round-1', 'X', 'Y', MatchResult::PlayerOneWin),
            $this->match('round-2', 'X', 'Z', MatchResult::Draw),
        ];
        $standings = [$this->standing('X', 4.0, wins: 1, draws: 1), $this->standing('Y', 0.0, losses: 1), $this->standing('Z', 1.0, draws: 1)];

        $result = $this->calculator->calculate('X', $matches, $standings, [$this->definition(TiebreakerType::GameWinPercentage)]);

        $this->assertEqualsWithDelta(0.75, $result['t'], 0.0001);
    }

    public function test_opponents_game_win_percentage_averages_the_opponents_gwp(): void
    {
        // A met B (0.5) and C (0.25)
        $this->assertEqualsWithDelta(0.375, $this->value('A', TiebreakerType::OpponentGameWinPercentage), 0.0001);
    }

    public function test_sonneborn_berger_adds_beaten_opponents_points_and_half_of_drawn_ones(): void
    {
        $this->assertEqualsWithDelta(4.0, $this->value('A', TiebreakerType::SonnebornBerger), 0.0001);
        $this->assertEqualsWithDelta(1.0, $this->value('B', TiebreakerType::SonnebornBerger), 0.0001);
        $this->assertEqualsWithDelta(0.5, $this->value('C', TiebreakerType::SonnebornBerger), 0.0001);
    }

    public function test_head_to_head_counts_points_won_against_players_tied_on_points(): void
    {
        // E beats F, F beats G, H beats E: E, F and H finish on one win each
        $matches = [
            $e1 = $this->match('round-1', 'E', 'F', MatchResult::PlayerOneWin),
            $this->match('round-2', 'F', 'G', MatchResult::PlayerOneWin),
            $h2 = $this->match('round-2', 'H', 'E', MatchResult::PlayerOneWin),
        ];
        $standings = [
            $this->standing('E', 2.0, wins: 1, losses: 1),
            $this->standing('F', 2.0, wins: 1, losses: 1),
            $this->standing('G', 0.0, losses: 1),
            $this->standing('H', 2.0, wins: 1),
        ];
        // The tournament scores a win 2: head-to-head uses those points
        $context = new TiebreakerContext(matchPoints: [
            $e1->id()->value => ['E' => 2.0, 'F' => 0.0],
            $h2->id()->value => ['H' => 2.0, 'E' => 0.0],
        ]);
        $definition = [$this->definition(TiebreakerType::HeadToHead)];

        $this->assertSame(2.0, $this->calculator->calculate('E', $matches, $standings, $definition, $context)['t']);
        $this->assertSame(0.0, $this->calculator->calculate('F', $matches, $standings, $definition, $context)['t']);
        $this->assertSame(2.0, $this->calculator->calculate('H', $matches, $standings, $definition, $context)['t']);
        $this->assertSame(0.0, $this->calculator->calculate('G', $matches, $standings, $definition, $context)['t']);
    }

    public function test_random_is_stable_for_a_seed_and_changes_with_it(): void
    {
        $definition = [$this->definition(TiebreakerType::Random)];
        $seeded = new TiebreakerContext(randomSeed: 'seed-1');

        $first = $this->calculator->calculate('A', $this->matches, $this->standings, $definition, $seeded)['t'];
        $again = $this->calculator->calculate('A', $this->matches, $this->standings, $definition, $seeded)['t'];
        $other = $this->calculator->calculate('B', $this->matches, $this->standings, $definition, $seeded)['t'];
        $reseeded = $this->calculator->calculate('A', $this->matches, $this->standings, $definition, new TiebreakerContext(randomSeed: 'seed-2'))['t'];

        $this->assertSame($first, $again);
        $this->assertNotSame($first, $other);
        $this->assertNotSame($first, $reseeded);
        $this->assertGreaterThanOrEqual(0.0, $first);
        $this->assertLessThan(1.0, $first);
    }

    public function test_progressive_uses_the_tournament_points_and_the_round_numbers(): void
    {
        // Round ids sort the other way round from their numbers
        $won = $this->match('round-b', 'P', 'Q', MatchResult::PlayerOneWin);
        $lost = $this->match('round-a', 'P', 'R', MatchResult::PlayerTwoWin);
        $context = new TiebreakerContext(
            matchPoints: [$won->id()->value => ['P' => 2.0, 'Q' => 0.0], $lost->id()->value => ['P' => 0.0, 'R' => 2.0]],
            roundNumbers: ['round-b' => 1, 'round-a' => 2],
        );

        $result = $this->calculator->calculate('P', [$lost, $won], [$this->standing('P', 2.0, wins: 1, losses: 1)], [$this->definition(TiebreakerType::Progressive)], $context);

        // After round 1: 2; after round 2: 2. Progressive = 2 + 2
        $this->assertSame(4.0, $result['t']);
    }

    private function value(string $participantId, TiebreakerType $type): float
    {
        return $this->calculator->calculate($participantId, $this->matches, $this->standings, [$this->definition($type)])['t'];
    }

    private function definition(TiebreakerType $type): TiebreakerDefinition
    {
        return new TiebreakerDefinition('t', $type->value, $type);
    }

    private function match(string $roundId, string $player1, string $player2, MatchResult $result, ?int $score1 = null, ?int $score2 = null): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1,
            player2Id: $player2,
            result: $result,
            player1Score: $score1,
            player2Score: $score2,
        );
    }

    private function standing(string $participantId, float $points, int $wins = 0, int $draws = 0, int $losses = 0): Standing
    {
        return new Standing(
            id: 'standing-'.$participantId,
            tournamentId: 'tournament-1',
            participantId: $participantId,
            rank: 0,
            matchesPlayed: $wins + $draws + $losses,
            wins: $wins,
            draws: $draws,
            losses: $losses,
            byes: 0,
            points: $points,
        );
    }
}
