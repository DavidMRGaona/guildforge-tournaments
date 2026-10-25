<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Services\StandingCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Standings from the tournament's own scoring rules and tiebreaker definitions.
 */
final class StandingCalculatorWithRulesTest extends TestCase
{
    private StandingCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StandingCalculatorService;
    }

    public function test_points_come_from_the_scoring_rules_including_the_bye_rule(): void
    {
        [$a, $b, $c, $d, $e] = $this->participants(5);
        $matches = [
            $this->match('r1', $a, $b, MatchResult::PlayerOneWin),
            $this->match('r1', $c, $d, MatchResult::Draw),
            $this->match('r1', $e, null, MatchResult::Bye),
        ];

        $standings = $this->byParticipant($this->service->calculateWithRules(
            [$a, $b, $c, $d, $e],
            $matches,
            $this->rules(win: 3, draw: 1, loss: 0, bye: 2),
            [],
        ));

        $this->assertSame([3.0, 1, 0, 0, 0], $this->summary($standings[$a->id()->value]));
        $this->assertSame([0.0, 0, 0, 1, 0], $this->summary($standings[$b->id()->value]));
        $this->assertSame([1.0, 0, 1, 0, 0], $this->summary($standings[$c->id()->value]));
        $this->assertSame([2.0, 0, 0, 0, 1], $this->summary($standings[$e->id()->value]));
        $this->assertSame(1, $standings[$e->id()->value]->matchesPlayed());
    }

    public function test_unplayed_matches_do_not_count(): void
    {
        [$a, $b] = $this->participants(2);

        $standings = $this->byParticipant($this->service->calculateWithRules(
            [$a, $b],
            [$this->match('r1', $a, $b, MatchResult::NotPlayed)],
            $this->rules(),
            [],
        ));

        $this->assertSame(0, $standings[$a->id()->value]->matchesPlayed());
        $this->assertSame(0.0, $standings[$a->id()->value]->points());
    }

    public function test_stat_based_rules_read_the_match_stats(): void
    {
        [$a, $b] = $this->participants(2);
        $rules = [
            ScoringRule::fromArray(['name' => 'Victoria amplia', 'points' => 5, 'priority' => 10, 'condition' => ['type' => 'margin_diff', 'result_value' => null, 'stat' => 'vp', 'operator' => '>=', 'value' => 20]]),
            ...$this->rules(),
        ];
        $match = $this->match('r1', $a, $b, MatchResult::PlayerOneWin, ['vp' => 80], ['vp' => 40]);

        $standings = $this->byParticipant($this->service->calculateWithRules([$a, $b], [$match], $rules, []));

        $this->assertSame(5.0, $standings[$a->id()->value]->points());
        $this->assertSame(0.0, $standings[$b->id()->value]->points());
    }

    public function test_ties_on_points_are_broken_by_the_configured_tiebreakers_in_order(): void
    {
        [$a, $b, $c, $d] = $this->participants(4);
        // Round 1: A beats B, C beats D. Round 2: A beats C, D beats B.
        // A 6, C 3, D 3, B 0. C met A (6) and D (3) = 9; D met C (3) and B (0) = 3.
        $matches = [
            $this->match('r1', $a, $b, MatchResult::PlayerOneWin),
            $this->match('r1', $c, $d, MatchResult::PlayerOneWin),
            $this->match('r2', $a, $c, MatchResult::PlayerOneWin),
            $this->match('r2', $d, $b, MatchResult::PlayerOneWin),
        ];
        $tiebreakers = [TiebreakerDefinition::fromArray(['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz'])];

        $standings = $this->service->calculateWithRules([$b, $d, $c, $a], $matches, $this->rules(), $tiebreakers);

        $this->assertSame(
            [$a->id()->value, $c->id()->value, $d->id()->value, $b->id()->value],
            array_map(static fn (Standing $s): string => $s->participantId(), $standings)
        );
        $this->assertSame([1, 2, 3, 4], array_map(static fn (Standing $s): int => $s->rank(), $standings));
        $this->assertSame(9.0, $standings[1]->calculatedTiebreakers()['buchholz']);
        $this->assertSame(9.0, $standings[1]->buchholz());
    }

    public function test_an_ascending_tiebreaker_ranks_the_lower_value_first(): void
    {
        [$a, $b, $c, $d] = $this->participants(4);
        $matches = [
            $this->match('r1', $a, $b, MatchResult::PlayerOneWin),
            $this->match('r1', $c, $d, MatchResult::PlayerOneWin),
            $this->match('r2', $a, $c, MatchResult::PlayerOneWin),
            $this->match('r2', $d, $b, MatchResult::PlayerOneWin),
        ];
        $tiebreakers = [TiebreakerDefinition::fromArray(['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'direction' => 'asc'])];

        $standings = $this->service->calculateWithRules([$a, $b, $c, $d], $matches, $this->rules(), $tiebreakers);

        $this->assertSame($d->id()->value, $standings[1]->participantId());
        $this->assertSame($c->id()->value, $standings[2]->participantId());
    }

    public function test_numeric_match_stats_are_accumulated_per_player(): void
    {
        [$a, $b] = $this->participants(2);
        $matches = [
            $this->match('r1', $a, $b, MatchResult::PlayerOneWin, ['vp' => 80, 'painted' => true], ['vp' => 40]),
            $this->match('r2', $b, $a, MatchResult::PlayerOneWin, ['vp' => 70], ['vp' => '55']),
        ];

        $standings = $this->byParticipant($this->service->calculateWithRules([$a, $b], $matches, $this->rules(), []));

        $this->assertSame(['vp' => 135.0], $standings[$a->id()->value]->accumulatedStats());
        $this->assertSame(['vp' => 110.0], $standings[$b->id()->value]->accumulatedStats());
    }

    public function test_it_returns_nothing_without_participants(): void
    {
        $this->assertSame([], $this->service->calculateWithRules([], [], $this->rules(), []));
    }

    /**
     * @return array<ScoringRule>
     */
    private function rules(float $win = 3, float $draw = 1, float $loss = 0, ?float $bye = null): array
    {
        $points = ['win' => $win, 'draw' => $draw, 'loss' => $loss];
        if ($bye !== null) {
            $points['bye'] = $bye;
        }

        return array_map(
            static fn (string $result, float $value): ScoringRule => ScoringRule::fromArray([
                'name' => $result,
                'points' => $value,
                'priority' => 5,
                'condition' => ['type' => 'result', 'result_value' => $result, 'stat' => null, 'operator' => null, 'value' => null],
            ]),
            array_keys($points),
            array_values($points),
        );
    }

    /**
     * @return array<Participant>
     */
    private function participants(int $count): array
    {
        $participants = [];
        for ($i = 0; $i < $count; $i++) {
            $participants[] = new Participant(
                id: ParticipantId::generate(),
                tournamentId: 'tournament-id',
                status: ParticipantStatus::Confirmed,
                guestName: 'Jugador '.($i + 1),
            );
        }

        return $participants;
    }

    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    private function match(string $roundId, Participant $player1, ?Participant $player2, MatchResult $result, ?array $player1Stats = null, ?array $player2Stats = null): TournamentMatch
    {
        return new TournamentMatch(
            id: MatchId::generate(),
            roundId: $roundId,
            player1Id: $player1->id()->value,
            player2Id: $player2?->id()->value,
            result: $result,
            player1Stats: $player1Stats,
            player2Stats: $player2Stats,
        );
    }

    /**
     * @param  array<Standing>  $standings
     * @return array<string, Standing>
     */
    private function byParticipant(array $standings): array
    {
        $map = [];
        foreach ($standings as $standing) {
            $map[$standing->participantId()] = $standing;
        }

        return $map;
    }

    /**
     * @return array{float, int, int, int, int}
     */
    private function summary(Standing $standing): array
    {
        return [$standing->points(), $standing->wins(), $standing->draws(), $standing->losses(), $standing->byes()];
    }
}
