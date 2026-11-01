<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchHistoryModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The organization reviews the players' reports: confirms them, resets results and reads
 * the history of every change.
 */
final class MatchReportsPanelTest extends ModuleTestCase
{
    use InteractsWithModulePanel;
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

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->admin = $this->actingAsPanelAdmin();
        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
        ])->refresh();
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
        ])->refresh();
        $this->match = $this->match();
    }

    public function test_pending_report_badge_and_confirm_action(): void
    {
        $this->report($this->match);

        $this->matchesManager()
            ->assertTableColumnStateSet('report_status', __('tournaments::messages.report_status.pending'), $this->match)
            ->assertTableActionVisible('confirmReport', $this->match)
            ->callTableAction('confirmReport', $this->match)
            ->assertNotified(__('tournaments::messages.messages.report_confirmed'));

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame($this->admin->id, $match->confirmed_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_a_report_corrected_while_the_modal_was_open_is_not_confirmed(): void
    {
        $this->report($this->match);
        $manager = $this->matchesManager()->mountTableAction('confirmReport', $this->match);
        // The reporter corrected it meanwhile
        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);
        $report->correct(MatchResult::Draw, 15, 15, null, null, new DateTimeImmutable('2026-06-10 11:30:00'));
        $this->reports()->save($report);

        $manager->callMountedTableAction()
            ->assertNotified(__('tournaments::messages.errors.report_changed'));

        $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
    }

    public function test_a_disputed_report_shows_as_disputed(): void
    {
        $this->report($this->match, disputed: true);

        $this->matchesManager()
            ->assertTableColumnStateSet('report_status', __('tournaments::messages.report_status.disputed'), $this->match)
            ->assertTableActionVisible('confirmReport', $this->match);
    }

    public function test_without_a_report_there_is_nothing_to_confirm(): void
    {
        $this->matchesManager()
            ->assertTableColumnStateSet('report_status', null, $this->match)
            ->assertTableActionHidden('confirmReport', $this->match)
            ->assertTableActionHidden('resetResult', $this->match)
            ->assertTableActionHidden('history', $this->match);
    }

    public function test_reset_requires_a_reason_and_writes_history(): void
    {
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $this->admin->id));

        $this->matchesManager()
            ->callTableAction('resetResult', $this->match, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(MatchResult::Draw, $this->match->refresh()->result);

        $this->matchesManager()
            ->callTableAction('resetResult', $this->match, data: ['reason' => 'Wrong table'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tournaments::messages.messages.result_reset'));

        $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
        $entry = MatchHistoryModel::query()->where('match_id', $this->match->id)->where('action', MatchHistoryAction::Reset->value)->sole();
        $this->assertSame('Wrong table', $entry->reason);
    }

    public function test_reset_discards_a_report_waiting_for_confirmation(): void
    {
        $this->report($this->match);

        $this->matchesManager()
            ->assertTableActionVisible('resetResult', $this->match)
            ->callTableAction('resetResult', $this->match, data: ['reason' => 'Report again'])
            ->assertHasNoTableActionErrors();

        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_history_modal_lists_entries(): void
    {
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $this->admin->id));
        $this->travel(5)->minutes();
        $this->results()->resetResult($this->match->id, $this->admin->id, 'Wrong table');

        $this->matchesManager()
            ->assertTableActionVisible('history', $this->match)
            ->mountTableAction('history', $this->match)
            ->assertMountedActionModalSee([
                MatchHistoryAction::Recorded->label(),
                MatchHistoryAction::Reset->label(),
                'Wrong table',
            ]);
    }

    public function test_confirm_pending_reports_in_round(): void
    {
        $disputed = $this->match();
        $this->report($this->match);
        $this->report($disputed, disputed: true);

        $this->roundsManager()
            ->assertTableActionVisible('confirmPendingReports', $this->round)
            ->callTableAction('confirmPendingReports', $this->round)
            ->assertNotified(trans_choice('tournaments::messages.messages.pending_reports_confirmed', 1, ['count' => 1]));

        $this->assertSame(MatchResult::PlayerOneWin, $this->match->refresh()->result);
        $this->assertSame(MatchResult::NotPlayed, $disputed->refresh()->result);
    }

    public function test_confirm_pending_reports_is_hidden_without_pending_reports(): void
    {
        $this->report($this->match, disputed: true);

        $this->roundsManager()->assertTableActionHidden('confirmPendingReports', $this->round);
    }

    public function test_manage_matches_keeps_a_result_players_recorded_while_it_was_open(): void
    {
        $this->report($this->match);
        $manager = $this->roundsManager()->mountTableAction('manageMatches', $this->round);
        // The rival confirmed meanwhile: the match now has its official result
        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);
        app(MatchReportingServiceInterface::class)->confirm($this->match->id, (string) $this->match->player2?->user_id, $report->fingerprint());

        $manager->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertSame(MatchResult::PlayerOneWin, $this->match->refresh()->result);
        $this->assertSame(0, MatchHistoryModel::query()->where('match_id', $this->match->id)->where('action', MatchHistoryAction::Recorded->value)->count());
    }

    public function test_manage_matches_does_not_overwrite_a_row_that_changed_meanwhile(): void
    {
        $manager = $this->roundsManager()->mountTableAction('manageMatches', $this->round);
        $rows = $manager->get('mountedActions.0.data.matches');
        $this->assertIsArray($rows);
        $key = array_key_first($rows);
        $this->assertNotNull($key);
        $rows[$key]['result'] = MatchResult::Draw->value;
        // Recorded elsewhere while the slide-over was open
        $this->results()->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::PlayerTwoWin, UserModel::factory()->create()->id));

        $manager->set('mountedActions.0.data.matches', $rows)
            ->callMountedTableAction()
            ->assertNotified(__('tournaments::messages.errors.results_changed_meanwhile'));

        $this->assertSame(MatchResult::PlayerTwoWin, $this->match->refresh()->result);
    }

    public function test_completing_a_round_with_reports_waiting_says_so(): void
    {
        $this->report($this->match);

        $this->roundsManager()
            ->callTableAction('completeRound', $this->round)
            ->assertNotified(__('tournaments::messages.errors.matches_not_completed'));

        $this->assertSame('in_progress', $this->round->refresh()->status->value);
    }

    private function report(MatchModel $match, bool $disputed = false): void
    {
        $report = new MatchReport($match->id, MatchResult::PlayerOneWin, (string) $match->player1->user_id, new DateTimeImmutable('2026-06-10 11:00:00'), 20, 15);
        if ($disputed) {
            $report->dispute((string) $match->player2?->user_id, 'We drew', new DateTimeImmutable('2026-06-10 11:05:00'));
        }

        $this->reports()->save($report);
    }

    private function match(): MatchModel
    {
        return MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant()->id,
            'player_2_id' => $this->participant()->id,
            'result' => 'not_played',
            'table_number' => MatchModel::query()->where('round_id', $this->round->id)->count() + 1,
        ])->refresh();
    }

    private function participant(): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
    }

    /**
     * @return Testable<MatchesRelationManager>
     */
    private function matchesManager(): Testable
    {
        return Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $this->round, 'pageClass' => EditTournament::class]);
    }

    /**
     * @return Testable<RoundsRelationManager>
     */
    private function roundsManager(): Testable
    {
        return Livewire::test(RoundsRelationManager::class, ['ownerRecord' => $this->tournament, 'pageClass' => EditTournament::class]);
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
