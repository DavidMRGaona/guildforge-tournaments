<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Database\Seeders\DevelopmentSeeder;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The development data must look like a real tournament: Swiss pairings never repeat a
 * match-up, and the standings are the ones the module computes from the results.
 */
final class DevelopmentSeederTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const array TOURNAMENTS_WITH_ROUNDS = ['liga-w40k-enero-2026', 'campeonato-juegos-mesa-2025'];

    protected function setUp(): void
    {
        parent::setUp();

        // database/ is outside the module's autoloaded src/
        require_once dirname(__DIR__, 3).'/database/seeders/GameProfilesSeeder.php';
        require_once dirname(__DIR__, 3).'/database/seeders/DevelopmentSeeder.php';

        UserModel::factory()->count(6)->create();
        EventModel::factory()->count(3)->create();
    }

    public function test_no_two_participants_meet_twice(): void
    {
        $this->seed(DevelopmentSeeder::class);

        foreach (self::TOURNAMENTS_WITH_ROUNDS as $slug) {
            $pairings = array_map(
                static function (MatchModel $match): string {
                    $players = [$match->player_1_id, $match->player_2_id];
                    sort($players);

                    return implode(' vs ', $players);
                },
                $this->matchesOf($slug),
            );

            $this->assertNotEmpty($pairings, $slug);
            $this->assertSame(array_unique($pairings), $pairings, "{$slug} repeats a pairing");
        }
    }

    public function test_the_standings_follow_the_played_matches(): void
    {
        $this->seed(DevelopmentSeeder::class);

        foreach (self::TOURNAMENTS_WITH_ROUNDS as $slug) {
            $records = $this->recordsFromMatches($slug);
            $standings = StandingModel::query()
                ->where('tournament_id', $this->tournament($slug)->id)
                ->orderBy('rank')
                ->get();

            $this->assertCount(count($records), $standings, $slug);
            $this->assertSame(range(1, count($records)), $standings->pluck('rank')->all(), "{$slug} ranks");

            $previousPoints = PHP_FLOAT_MAX;
            foreach ($standings as $standing) {
                $record = $records[$standing->participant_id];
                $this->assertSame(
                    [$record['wins'], $record['draws'], $record['losses']],
                    [$standing->wins, $standing->draws, $standing->losses],
                    "{$slug}: record of rank {$standing->rank}",
                );
                $this->assertEqualsWithDelta(3 * $record['wins'] + $record['draws'], (float) $standing->points, 0.001, "{$slug}: points of rank {$standing->rank}");
                $this->assertLessThanOrEqual($previousPoints, (float) $standing->points, "{$slug}: rank {$standing->rank} has more points than the one above");
                $previousPoints = (float) $standing->points;
            }
        }
    }

    public function test_running_the_seeder_again_keeps_one_standing_per_participant(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        foreach (self::TOURNAMENTS_WITH_ROUNDS as $slug) {
            $tournament = $this->tournament($slug);

            $this->assertSame(
                $tournament->participants()->count(),
                StandingModel::query()->where('tournament_id', $tournament->id)->count(),
                $slug,
            );
        }
    }

    // Enabling the module runs its seeders before its provider is registered
    public function test_the_standings_are_computed_before_the_provider_is_registered(): void
    {
        $this->app->offsetUnset(StandingsServiceInterface::class);

        $this->seed(DevelopmentSeeder::class);

        foreach (self::TOURNAMENTS_WITH_ROUNDS as $slug) {
            $this->assertSame(
                $this->tournament($slug)->participants()->count(),
                StandingModel::query()->where('tournament_id', $this->tournament($slug)->id)->count(),
                $slug,
            );
        }
    }

    private function tournament(string $slug): TournamentModel
    {
        return TournamentModel::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<int, MatchModel>
     */
    private function matchesOf(string $slug): array
    {
        return MatchModel::query()
            ->whereHas('round', fn ($query) => $query->where('tournament_id', $this->tournament($slug)->id))
            ->get()
            ->all();
    }

    /**
     * Wins, draws and losses of every participant, counted from the played matches.
     *
     * @return array<string, array{wins: int, draws: int, losses: int}>
     */
    private function recordsFromMatches(string $slug): array
    {
        $wins = $draws = $losses = [];
        foreach ($this->tournament($slug)->participants()->pluck('id') as $participantId) {
            $wins[(string) $participantId] = $draws[(string) $participantId] = $losses[(string) $participantId] = 0;
        }

        $decisive = [MatchResult::PlayerOneWin, MatchResult::PlayerTwoWin, MatchResult::Draw];
        foreach ($this->matchesOf($slug) as $match) {
            if (! in_array($match->result, $decisive, true)) {
                continue;
            }

            $one = $match->player_1_id;
            $two = $match->player_2_id;
            // A played match always has a second player; only byes leave it empty
            $this->assertNotNull($two);

            match ($match->result) {
                MatchResult::PlayerOneWin => [$wins[$one]++, $losses[$two]++],
                MatchResult::PlayerTwoWin => [$wins[$two]++, $losses[$one]++],
                MatchResult::Draw => [$draws[$one]++, $draws[$two]++],
            };
        }

        $records = [];
        foreach ($wins as $participantId => $won) {
            $records[$participantId] = ['wins' => $won, 'draws' => $draws[$participantId], 'losses' => $losses[$participantId]];
        }

        return $records;
    }
}
