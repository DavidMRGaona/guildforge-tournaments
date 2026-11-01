<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\DTOs\Response\PlayerMatchResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The player's own match of the current round, with what they may do with its result.
 */
final class MatchQueryServicePlayerMatchTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $round;

    private MatchModel $match;

    private UserModel $one;

    private UserModel $two;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
            'stat_definitions' => [
                ['key' => 'vp', 'name' => 'Puntos de victoria', 'type' => 'integer', 'min_value' => 0, 'max_value' => 100, 'per_player' => true, 'required' => true],
            ],
        ]);
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $this->one = UserModel::factory()->create(['name' => 'Ana']);
        $this->two = UserModel::factory()->create(['name' => 'Luis']);
        $this->match = MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant($this->one)->id,
            'player_2_id' => $this->participant($this->two)->id,
            'table_number' => 3,
            'result' => 'not_played',
        ]);
    }

    public function test_player_match_offers_the_actions_of_the_rules(): void
    {
        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame($this->match->id, $mine->match->id);
        $this->assertSame(1, $mine->side);
        $this->assertSame('Luis', $mine->opponentName);
        $this->assertFalse($mine->opponentIsGuest);
        $this->assertTrue($mine->actions->canReport);
        $this->assertNull($mine->report);
        $this->assertSame(ResultReporting::PlayersWithConfirmation, $mine->resultReporting);
        $this->assertSame(['vp'], array_map(static fn ($stat): string => $stat->key, $mine->statDefinitions));

        $report = new MatchReport($this->match->id, MatchResult::PlayerOneWin, $this->one->id, new DateTimeImmutable('2026-06-10 11:00:00'), 20, 15);
        $this->reports()->save($report);

        $theirs = $this->queries()->findPlayerMatch($this->tournament->id, $this->two->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $theirs);
        $this->assertSame(2, $theirs->side);
        $this->assertSame('Ana', $theirs->opponentName);
        $this->assertTrue($theirs->actions->canConfirm);
        $this->assertTrue($theirs->actions->canDispute);
        $this->assertFalse($theirs->actions->canReport);
        $this->assertNotNull($theirs->report);
        $this->assertSame('Ana', $theirs->report->reportedByName);
        $this->assertSame($this->reports()->find($this->match->id)?->fingerprint(), $theirs->report->fingerprint);
        $this->assertSame('pending', $theirs->toArray()['report']['status']);
    }

    public function test_player_match_is_null_without_round_in_progress_or_for_guests(): void
    {
        $this->assertNull($this->queries()->findPlayerMatch($this->tournament->id, UserModel::factory()->create()->id));

        $this->round->update(['status' => 'finished']);

        $this->assertNull($this->queries()->findPlayerMatch($this->tournament->id, $this->one->id));
    }

    public function test_a_guest_opponent_is_flagged(): void
    {
        $guest = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'guest_name' => 'Invitada',
            'guest_email' => 'invitada@example.com',
            'status' => 'confirmed',
        ]);
        $this->match->update(['player_2_id' => $guest->id]);

        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame('Invitada', $mine->opponentName);
        $this->assertTrue($mine->opponentIsGuest);
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function closedReasons(): iterable
    {
        yield 'open to the players' => ['players_with_confirmation', 'in_progress', 'confirmed', null];
        yield 'the organization records' => ['admin_only', 'in_progress', 'confirmed', 'admin_only'];
        yield 'withdrawn' => ['players_trusted', 'in_progress', 'withdrawn', 'not_playing'];
        yield 'disqualified' => ['players_with_confirmation', 'in_progress', 'disqualified', 'not_playing'];
        yield 'round not started' => ['players_with_confirmation', 'pending', 'confirmed', 'round_pending'];
        yield 'admin only wins over the round' => ['admin_only', 'pending', 'withdrawn', 'admin_only'];
    }

    #[DataProvider('closedReasons')]
    public function test_player_match_says_why_the_player_cannot_report(string $mode, string $round, string $status, ?string $reason): void
    {
        $this->tournament->update(['result_reporting' => $mode]);
        $this->round->update(['status' => $round]);
        ParticipantModel::query()->where('user_id', $this->one->id)->update(['status' => $status]);

        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame($reason, $mine->toArray()['closed_reason']);
    }

    /**
     * @return iterable<string, array{array<int, array<string, mixed>>|null, float}>
     */
    public static function byeRules(): iterable
    {
        $rule = static fn (string $result, float $points): array => ['name' => $result, 'points' => $points, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => $result, 'stat' => null, 'operator' => null, 'value' => null]];

        yield 'a rule for the bye' => [[$rule('win', 3), $rule('bye', 2)], 2.0];
        yield 'a bye counts as a win' => [[$rule('win', 4), $rule('draw', 2)], 4.0];
        yield 'no points for a win' => [[$rule('draw', 1)], 0.0];
        yield 'the default rules' => [null, 3.0];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rules
     */
    #[DataProvider('byeRules')]
    public function test_a_bye_says_the_points_the_scoring_rules_give_it(?array $rules, float $points): void
    {
        $this->tournament->update(['scoring_rules' => $rules]);
        $this->match->update(['player_2_id' => null, 'result' => 'bye']);

        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame($points, $mine->toArray()['bye_points']);
    }

    public function test_a_match_with_a_rival_has_no_bye_points(): void
    {
        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertNull($mine->toArray()['bye_points']);
    }

    public function test_history_lists_entries_with_names_and_actions(): void
    {
        $admin = UserModel::factory()->create(['name' => 'Organización']);
        app(MatchResultServiceInterface::class)->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $admin->id));
        $this->travel(5)->minutes();
        app(MatchResultServiceInterface::class)->resetResult($this->match->id, $admin->id, 'Wrong table');

        $history = $this->queries()->getHistory($this->match->id);

        $this->assertCount(2, $history);
        $this->assertSame([MatchHistoryAction::Recorded, MatchHistoryAction::Reset], array_map(static fn ($entry): MatchHistoryAction => $entry->action, $history));
        $this->assertSame('Organización', $history[0]->changedByName);
        $this->assertSame('Wrong table', $history[1]->reason);
    }

    private function participant(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    private function queries(): MatchQueryServiceInterface
    {
        return app(MatchQueryServiceInterface::class);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return app(MatchReportRepositoryInterface::class);
    }
}
