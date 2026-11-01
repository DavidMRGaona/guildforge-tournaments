<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchHistoryModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Every change of an official result leaves an entry in the match history.
 */
final class MatchResultHistoryTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $round;

    private MatchModel $match;

    private UserModel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = UserModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
        ]);
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $this->match = MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant()->id,
            'player_2_id' => $this->participant()->id,
            'result' => 'not_played',
        ]);
    }

    public function test_recording_a_result_writes_a_recorded_history_entry_with_before_and_after(): void
    {
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::PlayerOneWin, $this->admin->id, player1Score: 20, player2Score: 15));
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $this->admin->id, player1Score: 18, player2Score: 18));

        $entries = MatchHistoryModel::query()->where('match_id', $this->match->id)->orderBy('created_at')->orderBy('new_result', 'desc')->get();

        $this->assertCount(2, $entries);
        $first = $entries->firstWhere('new_result', MatchResult::PlayerOneWin);
        $second = $entries->firstWhere('new_result', MatchResult::Draw);
        $this->assertInstanceOf(MatchHistoryModel::class, $first);
        $this->assertInstanceOf(MatchHistoryModel::class, $second);

        $this->assertSame(MatchResult::NotPlayed, $first->previous_result);
        $this->assertNull($first->previous_player_1_score);
        $this->assertSame(20, $first->new_player_1_score);
        $this->assertSame(15, $first->new_player_2_score);
        $this->assertSame(MatchHistoryAction::Recorded, $first->action);
        $this->assertSame($this->admin->id, $first->changed_by_id);
        $this->assertSame(now()->getTimestamp(), $first->changed_at->getTimestamp());

        $this->assertSame(MatchResult::PlayerOneWin, $second->previous_result);
        $this->assertSame(20, $second->previous_player_1_score);
        $this->assertSame(18, $second->new_player_1_score);
    }

    public function test_recording_the_same_result_again_writes_no_entry(): void
    {
        $dto = new RecordMatchResultDTO($this->match->id, MatchResult::PlayerOneWin, $this->admin->id, player1Score: 2, player2Score: 1);

        $this->results()->recordResult($dto);
        $this->results()->recordResult($dto);

        $this->assertSame(1, MatchHistoryModel::query()->where('match_id', $this->match->id)->count());
    }

    public function test_recording_discards_a_pending_report(): void
    {
        $this->reports()->save($this->pendingReport());

        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $this->admin->id));

        $this->assertNull($this->reports()->find($this->match->id));
        $this->assertSame(MatchResult::Draw, $this->match->refresh()->result);
    }

    public function test_a_confirmation_stores_both_players_and_logs_the_confirmer(): void
    {
        $reporter = UserModel::factory()->create();
        $confirmer = UserModel::factory()->create();

        $this->results()->recordResult(new RecordMatchResultDTO(
            matchId: $this->match->id,
            result: MatchResult::PlayerTwoWin,
            reportedById: $reporter->id,
            action: MatchHistoryAction::Confirmed,
            confirmedById: $confirmer->id,
            changedById: $confirmer->id,
        ));

        $match = $this->match->refresh();
        $this->assertSame($reporter->id, $match->reported_by_id);
        $this->assertSame($confirmer->id, $match->confirmed_by_id);
        $this->assertNotNull($match->confirmed_at);

        $entry = MatchHistoryModel::query()->where('match_id', $this->match->id)->sole();
        $this->assertSame(MatchHistoryAction::Confirmed, $entry->action);
        $this->assertSame($confirmer->id, $entry->changed_by_id);
    }

    public function test_reset_stores_the_reason_and_clears_the_result(): void
    {
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::PlayerOneWin, $this->admin->id, player1Score: 3, player2Score: 1));

        $this->results()->resetResult($this->match->id, $this->admin->id, 'Wrong table');

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::NotPlayed, $match->result);
        $this->assertNull($match->player_1_score);
        $this->assertNull($match->reported_by_id);

        $entry = MatchHistoryModel::query()->where('match_id', $this->match->id)->where('action', 'reset')->sole();
        $this->assertSame('Wrong table', $entry->reason);
        $this->assertSame(MatchResult::PlayerOneWin, $entry->previous_result);
        $this->assertSame(MatchResult::NotPlayed, $entry->new_result);
        $this->assertSame(3, $entry->previous_player_1_score);
    }

    public function test_reset_of_a_pending_report_discards_it_with_its_reason(): void
    {
        $this->reports()->save($this->pendingReport());

        $this->results()->resetResult($this->match->id, $this->admin->id, 'Players must report again');

        $this->assertNull($this->reports()->find($this->match->id));
        $entry = MatchHistoryModel::query()->where('match_id', $this->match->id)->sole();
        $this->assertSame(MatchHistoryAction::Reset, $entry->action);
        $this->assertSame('Players must report again', $entry->reason);
        $this->assertSame(MatchResult::NotPlayed, $entry->previous_result);
        $this->assertSame(MatchResult::NotPlayed, $entry->new_result);
    }

    public function test_reset_of_a_round_followed_by_another_is_locked(): void
    {
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::PlayerOneWin, $this->admin->id));
        $this->round->update(['status' => 'finished']);
        RoundModel::create(['tournament_id' => $this->tournament->id, 'round_number' => 2, 'status' => 'pending']);

        try {
            $this->results()->resetResult($this->match->id, $this->admin->id, 'Too late');
            $this->fail('A result the next round was paired from cannot be reset.');
        } catch (MatchResultLockedException) {
            $this->assertSame(MatchResult::PlayerOneWin, $this->match->refresh()->result);
            $this->assertSame(0, MatchHistoryModel::query()->where('action', 'reset')->count());
        }
    }

    private function pendingReport(): MatchReport
    {
        return new MatchReport(
            matchId: $this->match->id,
            result: MatchResult::PlayerOneWin,
            reportedById: UserModel::factory()->create()->id,
            reportedAt: new DateTimeImmutable('2026-06-10 11:00:00'),
        );
    }

    private function participant(): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
    }

    private function results(): MatchResultServiceInterface
    {
        return app(MatchResultServiceInterface::class);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return app(MatchReportRepositoryInterface::class);
    }
}
