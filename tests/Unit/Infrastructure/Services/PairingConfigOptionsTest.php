<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Services\SwissPairingService;
use PHPUnit\Framework\TestCase;

/**
 * The tournament's pairing options drive the pairing.
 */
final class PairingConfigOptionsTest extends TestCase
{
    private SwissPairingService $service;

    /** @var array<string, Participant> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SwissPairingService;
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            $this->players[$name] = new Participant(
                id: ParticipantId::generate(),
                tournamentId: 'tournament-id',
                status: ParticipantStatus::Confirmed,
                guestName: $name,
            );
        }
    }

    public function test_rematches_are_avoided_by_default(): void
    {
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 0, 'D' => 0], [['A', 'B']], new PairingConfig);

        $this->assertSame([['A', 'C'], ['B', 'D']], $pairs);
    }

    public function test_the_last_pair_is_not_forced_into_a_rematch_when_another_pairing_exists(): void
    {
        // Greedy would pair A-B and leave C-D, who already met; A-C and B-D avoid it
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 0, 'D' => 0], [['C', 'D']], new PairingConfig);

        $this->assertSame([['A', 'C'], ['B', 'D']], $pairs);
    }

    public function test_a_rematch_is_accepted_when_no_pairing_avoids_it(): void
    {
        // Three of the six possible pairs already met: no rematch-free round remains for A
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 0, 'D' => 0], [['A', 'B'], ['A', 'C'], ['A', 'D']], new PairingConfig);

        $this->assertCount(2, $pairs);
        $this->assertSame(['A', 'B', 'C', 'D'], $this->sortedPlayers($pairs));
    }

    public function test_a_score_group_pairs_its_top_half_against_its_bottom_half(): void
    {
        // All on 3 points, ranked A to D: 1 v 3 and 2 v 4
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 3, 'D' => 3], [], new PairingConfig);

        $this->assertSame([['A', 'C'], ['B', 'D']], $pairs);
    }

    public function test_the_lowest_player_of_an_odd_score_group_floats_down(): void
    {
        // A, B, C on 3 points and D, E, F on 0: C drops into the lower group, which pairs C v E and D v F
        $pairs = $this->pairs(['A', 'B', 'C', 'D', 'E', 'F'], ['A' => 3, 'B' => 3, 'C' => 3, 'D' => 0, 'E' => 0, 'F' => 0], [], new PairingConfig);

        $this->assertSame([['A', 'B'], ['C', 'E'], ['D', 'F']], $pairs);
    }

    public function test_a_rematch_inside_a_score_group_moves_to_the_next_opponent(): void
    {
        // A already met C: A takes the next one in line and B meets C
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 3, 'D' => 3], [['A', 'C']], new PairingConfig);

        $this->assertSame([['A', 'B'], ['C', 'D']], $pairs);
    }

    public function test_rematches_are_allowed_when_the_option_is_off(): void
    {
        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 0, 'D' => 0], [['A', 'B']], new PairingConfig(avoidRematches: false));

        $this->assertSame([['A', 'B'], ['C', 'D']], $pairs);
    }

    public function test_sorting_by_a_stat_orders_by_the_accumulated_stat(): void
    {
        $config = new PairingConfig(sortBy: PairingSortCriteria::Stat, sortByStat: 'vp');
        $stats = ['C' => 100, 'A' => 90, 'D' => 50, 'B' => 10];

        $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 3, 'B' => 3, 'C' => 0, 'D' => 0], [], $config, $stats);

        $this->assertSame([['C', 'A'], ['D', 'B']], $pairs);
    }

    public function test_random_sorting_does_not_follow_the_points(): void
    {
        $this->assertTrue($this->someSeedBreaksThePointsOrder(new PairingConfig(sortBy: PairingSortCriteria::Random)));
    }

    public function test_the_random_method_does_not_follow_the_points(): void
    {
        $this->assertTrue($this->someSeedBreaksThePointsOrder(new PairingConfig(method: PairingMethod::Random)));
    }

    public function test_the_bye_skips_players_at_the_maximum(): void
    {
        // E is last and already had its bye
        $pairs = $this->pairs(['A', 'B', 'C', 'D', 'E'], $this->ranked(), [['E', null]], new PairingConfig(maxByesPerPlayer: 1));

        $this->assertContains(['D', null], $pairs);
    }

    public function test_a_higher_maximum_lets_a_player_have_another_bye(): void
    {
        $pairs = $this->pairs(['A', 'B', 'C', 'D', 'E'], $this->ranked(), [['E', null]], new PairingConfig(maxByesPerPlayer: 2));

        $this->assertContains(['E', null], $pairs);
    }

    public function test_the_bye_can_go_to_the_highest_ranked(): void
    {
        $pairs = $this->pairs(['A', 'B', 'C', 'D', 'E'], $this->ranked(), [], new PairingConfig(byeAssignment: ByeAssignment::HighestRanked));

        $this->assertContains(['A', null], $pairs);
    }

    public function test_a_random_bye_only_goes_to_eligible_players(): void
    {
        $config = new PairingConfig(byeAssignment: ByeAssignment::Random);
        $receivers = [];
        for ($seed = 1; $seed <= 30; $seed++) {
            mt_srand($seed);
            $pairs = $this->pairs(['A', 'B', 'C', 'D', 'E'], $this->ranked(), [['D', null], ['E', null]], $config);
            foreach ($pairs as [$player, $opponent]) {
                if ($opponent === null) {
                    $receivers[$player] = true;
                }
            }
        }
        mt_srand();

        $this->assertSame([], array_intersect(['D', 'E'], array_keys($receivers)));
        $this->assertGreaterThan(1, count($receivers));
    }

    /**
     * @param  list<array{string, ?string}>  $pairs
     * @return list<string>
     */
    private function sortedPlayers(array $pairs): array
    {
        $players = array_values(array_filter(array_merge(...$pairs), static fn (?string $name): bool => $name !== null));
        sort($players);

        return $players;
    }

    /**
     * @return array<string, int>
     */
    private function ranked(): array
    {
        return ['A' => 12, 'B' => 9, 'C' => 6, 'D' => 3, 'E' => 0];
    }

    private function someSeedBreaksThePointsOrder(PairingConfig $config): bool
    {
        $pointsOrder = [['A', 'B'], ['C', 'D']];
        $broken = false;
        for ($seed = 1; $seed <= 30 && ! $broken; $seed++) {
            mt_srand($seed);
            $pairs = $this->pairs(['A', 'B', 'C', 'D'], ['A' => 9, 'B' => 6, 'C' => 3, 'D' => 0], [], $config);
            $this->assertCount(2, $pairs);
            $broken = $this->normalised($pairs) !== $pointsOrder;
        }
        mt_srand();

        return $broken;
    }

    /**
     * @param  list<array{string, ?string}>  $pairs
     * @return list<array{string, ?string}>
     */
    private function normalised(array $pairs): array
    {
        $pairs = array_map(static function (array $pair): array {
            if ($pair[1] !== null && strcmp($pair[0], $pair[1]) > 0) {
                return [$pair[1], $pair[0]];
            }

            return $pair;
        }, $pairs);
        sort($pairs);

        return $pairs;
    }

    /**
     * Pairings of round 2 as [player 1, player 2 or null for a bye], by name.
     *
     * @param  list<string>  $names
     * @param  array<string, int>  $points  Ranks follow the order given
     * @param  list<array{string, ?string}>  $previous
     * @param  array<string, int>  $stats  Accumulated "vp"
     * @return list<array{string, ?string}>
     */
    private function pairs(array $names, array $points, array $previous, PairingConfig $config, array $stats = []): array
    {
        $standings = [];
        $rank = 1;
        foreach ($points as $name => $value) {
            $standings[] = new Standing(
                id: 'standing-'.$name,
                tournamentId: 'tournament-id',
                participantId: $this->players[$name]->id()->value,
                rank: $rank++,
                matchesPlayed: 1,
                wins: 0,
                draws: 0,
                losses: 0,
                byes: 0,
                points: (float) $value,
                accumulatedStats: isset($stats[$name]) ? ['vp' => (float) $stats[$name]] : [],
            );
        }

        $matchups = array_map(fn (array $pair): array => [
            'player1Id' => $this->players[$pair[0]]->id()->value,
            'player2Id' => $pair[1] !== null ? $this->players[$pair[1]]->id()->value : null,
        ], $previous);

        $matches = $this->service->generatePairings(
            array_map(fn (string $name): Participant => $this->players[$name], $names),
            $standings,
            $matchups,
            2,
            $config,
        );

        return array_values(array_map(fn (TournamentMatch $match): array => [
            $this->playerName($match->player1Id()),
            $match->player2Id() !== null ? $this->playerName($match->player2Id()) : null,
        ], $matches));
    }

    private function playerName(string $participantId): string
    {
        foreach ($this->players as $name => $player) {
            if ($player->id()->value === $participantId) {
                return $name;
            }
        }

        return '?';
    }
}
