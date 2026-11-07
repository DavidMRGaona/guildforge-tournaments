<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Notifications\MatchResultDisputedNotification;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The «Partidas» tab of a tournament holds the actions of every match, where the disputed
 * result email sends the organization.
 */
final class MatchesTabTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $firstRound;

    private RoundModel $secondRound;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
        ])->refresh();
        $this->firstRound = $this->round(1, 'finished');
        $this->secondRound = $this->round(2, 'in_progress');
    }

    public function test_the_tournament_page_has_the_matches_tab(): void
    {
        $this->assertContains(MatchesRelationManager::class, TournamentResource::getRelations());
        $this->assertSame('Partidas', MatchesRelationManager::getTitle($this->tournament, EditTournament::class));
    }

    public function test_only_who_records_results_sees_the_tab(): void
    {
        $this->actingAs($this->userWith(['tournaments:tournaments.view_any', 'tournaments:tournaments.view', 'tournaments:tournaments.update']));
        $this->assertFalse(MatchesRelationManager::canViewForRecord($this->tournament, EditTournament::class));

        $this->actingAs($this->userWith(['tournaments:tournaments.view_any', 'tournaments:tournaments.view', 'tournaments:tournaments.report_results']));
        $this->assertTrue(MatchesRelationManager::canViewForRecord($this->tournament, EditTournament::class));
    }

    public function test_it_lists_the_last_round_and_filters_by_round(): void
    {
        $this->actingAsPanelAdmin();
        $old = $this->match($this->firstRound);
        $current = $this->match($this->secondRound);

        $this->matchesTab()
            ->assertCanSeeTableRecords([$current])
            ->assertCanNotSeeTableRecords([$old])
            ->assertTableColumnFormattedStateSet('round.round_number', 'Ronda 2', $current)
            ->filterTable('round_id', $this->firstRound->id)
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$current]);
    }

    public function test_the_report_filter_shows_the_disputed_ones(): void
    {
        $this->actingAsPanelAdmin();
        $disputed = $this->match($this->secondRound);
        $pending = $this->match($this->secondRound);
        $quiet = $this->match($this->secondRound);
        $this->report($disputed, disputed: true);
        $this->report($pending);

        $this->matchesTab()
            ->filterTable('report', 'disputed')
            ->assertCanSeeTableRecords([$disputed])
            ->assertCanNotSeeTableRecords([$pending, $quiet])
            ->filterTable('report', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$disputed, $quiet]);
    }

    public function test_a_disputed_report_is_confirmed_from_the_tab(): void
    {
        $admin = $this->actingAsPanelAdmin();
        $match = $this->match($this->secondRound);
        $this->report($match, disputed: true);

        $this->matchesTab()
            ->assertTableColumnStateSet('report_status', __('tournaments::messages.report_status.disputed'), $match)
            ->assertTableActionVisible('confirmReport', $match)
            ->callTableAction('confirmReport', $match)
            ->assertNotified(__('tournaments::messages.messages.report_confirmed'));

        $match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame($admin->id, $match->confirmed_by_id);
    }

    public function test_a_disputed_report_is_reset_from_the_tab(): void
    {
        $this->actingAsPanelAdmin();
        $match = $this->match($this->secondRound);
        $this->report($match, disputed: true);

        $this->matchesTab()
            ->callTableAction('resetResult', $match, ['reason' => 'They will play it again'])
            ->assertNotified(__('tournaments::messages.messages.result_reset'));

        $this->assertNull(app(MatchReportRepositoryInterface::class)->find($match->id));
        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
    }

    public function test_the_disputed_email_points_to_the_matches_tab(): void
    {
        $mail = (new MatchResultDisputedNotification(null, 'Ana', 'Torneo', 'torneo', 'Victoria jugador 1', 'We drew', toOrganization: true))
            ->toMail(UserModel::factory()->make());

        $this->assertContains('Revísalo en el panel, en la pestaña Partidas del torneo: puedes confirmarlo o restablecerlo.', $mail->introLines);
    }

    /**
     * @return Testable<MatchesRelationManager>
     */
    private function matchesTab(): Testable
    {
        return Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $this->tournament, 'pageClass' => EditTournament::class]);
    }

    private function round(int $number, string $status): RoundModel
    {
        return RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => $number,
            'status' => $status,
            'started_at' => now()->subHours(3 - $number),
        ])->refresh();
    }

    private function match(RoundModel $round): MatchModel
    {
        return MatchModel::create([
            'round_id' => $round->id,
            'player_1_id' => $this->participant()->id,
            'player_2_id' => $this->participant()->id,
            'result' => 'not_played',
            'table_number' => MatchModel::query()->where('round_id', $round->id)->count() + 1,
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

    private function report(MatchModel $match, bool $disputed = false): void
    {
        $report = new MatchReport($match->id, MatchResult::PlayerOneWin, (string) $match->player1->user_id, new DateTimeImmutable('2026-06-10 11:00:00'), 20, 15);
        if ($disputed) {
            $report->dispute((string) $match->player2?->user_id, 'We drew', new DateTimeImmutable('2026-06-10 11:05:00'));
        }

        app(MatchReportRepositoryInterface::class)->save($report);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'tournaments', 'action' => Str::afterLast($key, '.'), 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
