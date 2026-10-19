<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Policies;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Editing a tournament is not enough to record results or manage its participants:
 * tournaments.report_results and tournaments.manage_participants gate those panel actions.
 */
final class ResultsAndParticipantsPermissionsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string UPDATE = 'tournaments:tournaments.update';

    private const string REPORT_RESULTS = 'tournaments:tournaments.report_results';

    private const string MANAGE_PARTICIPANTS = 'tournaments:tournaments.manage_participants';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->tournament = TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de permisos',
            'slug' => 'torneo-de-permisos',
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => 'admin_only',
            'requires_manual_confirmation' => true,
        ])->refresh();
    }

    public function test_the_policy_asks_for_report_results(): void
    {
        $this->assertTrue($this->userWith([self::REPORT_RESULTS])->can('reportResults', $this->tournament));
        $this->assertFalse($this->userWith([self::UPDATE])->can('reportResults', $this->tournament));
    }

    public function test_the_policy_asks_for_manage_participants(): void
    {
        $this->assertTrue($this->userWith([self::MANAGE_PARTICIPANTS])->can('manageParticipants', $this->tournament));
        $this->assertFalse($this->userWith([self::UPDATE])->can('manageParticipants', $this->tournament));
    }

    public function test_report_results_allows_reporting_a_match_result(): void
    {
        $match = $this->match();
        $this->actingAs($this->userWith([self::UPDATE, self::REPORT_RESULTS]));

        $this->matchesManager($match)
            ->callTableAction('reportResult', $match, data: ['result' => MatchResult::Draw->value])
            ->assertHasNoTableActionErrors();

        $this->assertSame(MatchResult::Draw, $match->refresh()->result);
    }

    public function test_without_report_results_a_match_result_cannot_be_reported(): void
    {
        $match = $this->match();
        $this->actingAs($this->userWith([self::UPDATE, self::MANAGE_PARTICIPANTS]));

        $this->matchesManager($match)->assertTableActionHidden('reportResult', $match);

        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
    }

    public function test_report_results_allows_managing_the_matches_of_a_round(): void
    {
        $match = $this->match();
        $this->actingAs($this->userWith([self::UPDATE, self::REPORT_RESULTS]));

        $this->roundsManager()
            ->mountTableAction('manageMatches', $match->round)
            ->setTableActionData(['matches' => [[
                'match_id' => $match->id,
                'table_number' => $match->table_number,
                'player1_name' => 'Jugador 1',
                'player2_name' => 'Jugador 2',
                'result' => MatchResult::PlayerOneWin->value,
                'player_1_stats' => [],
                'player_2_stats' => [],
                'is_bye' => false,
            ]]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(MatchResult::PlayerOneWin, $match->refresh()->result);
    }

    public function test_without_report_results_the_matches_of_a_round_cannot_be_managed(): void
    {
        $match = $this->match();
        $this->actingAs($this->userWith([self::UPDATE, self::MANAGE_PARTICIPANTS]));

        $this->roundsManager()
            ->assertTableActionHidden('manageMatches', $match->round)
            // Round management itself stays with tournaments.update
            ->assertTableActionVisible('completeRound', $match->round)
            ->assertTableActionVisible('generateRound');
    }

    /**
     * @return array<string, array{string, ParticipantStatus}>
     */
    public static function participantRecordActions(): array
    {
        return [
            'confirm' => ['confirm', ParticipantStatus::Registered],
            'confirm email' => ['confirm_email', ParticipantStatus::Registered],
            'check in' => ['check_in', ParticipantStatus::Confirmed],
            'withdraw' => ['withdraw', ParticipantStatus::Confirmed],
            'disqualify' => ['disqualify', ParticipantStatus::Confirmed],
            'delete' => ['delete', ParticipantStatus::Confirmed],
        ];
    }

    #[DataProvider('participantRecordActions')]
    public function test_manage_participants_offers_the_participant_action(string $action, ParticipantStatus $status): void
    {
        $participant = $this->participant($status);
        $this->actingAs($this->userWith([self::UPDATE, self::MANAGE_PARTICIPANTS]));

        $this->participantsManager()->assertTableActionVisible($action, $participant);
    }

    #[DataProvider('participantRecordActions')]
    public function test_without_manage_participants_the_participant_action_is_not_offered(string $action, ParticipantStatus $status): void
    {
        $participant = $this->participant($status);
        $this->actingAs($this->userWith([self::UPDATE, self::REPORT_RESULTS]));

        $this->participantsManager()->assertTableActionHidden($action, $participant);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function participantBulkActions(): array
    {
        return [
            'confirm selected' => ['confirm_selected'],
            'check in selected' => ['check_in_selected'],
            'disqualify selected' => ['disqualify_selected'],
            'delete selected' => ['delete'],
        ];
    }

    #[DataProvider('participantBulkActions')]
    public function test_manage_participants_offers_the_bulk_action(string $action): void
    {
        $this->participant(ParticipantStatus::Registered);
        $this->actingAs($this->userWith([self::UPDATE, self::MANAGE_PARTICIPANTS]));

        $this->participantsManager()->assertTableBulkActionVisible($action);
    }

    #[DataProvider('participantBulkActions')]
    public function test_without_manage_participants_the_bulk_action_is_not_offered(string $action): void
    {
        $this->participant(ParticipantStatus::Registered);
        $this->actingAs($this->userWith([self::UPDATE, self::REPORT_RESULTS]));

        $this->participantsManager()->assertTableBulkActionHidden($action);
    }

    public function test_manage_participants_allows_adding_and_checking_in_participants(): void
    {
        $participant = $this->participant(ParticipantStatus::Confirmed);
        $this->actingAs($this->userWith([self::UPDATE, self::MANAGE_PARTICIPANTS]));

        $this->participantsManager()
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['guest_name' => 'Invitada', 'status' => ParticipantStatus::Registered->value])
            ->assertHasNoTableActionErrors()
            ->callTableAction('check_in', $participant)
            ->assertHasNoTableActionErrors();

        $this->assertTrue(ParticipantModel::query()->where('guest_name', 'Invitada')->exists());
        $this->assertSame(ParticipantStatus::CheckedIn, $participant->refresh()->status);
    }

    public function test_without_manage_participants_no_participant_can_be_added(): void
    {
        $this->actingAs($this->userWith([self::UPDATE, self::REPORT_RESULTS]));

        $this->participantsManager()->assertTableActionHidden('create');
    }

    public function test_a_forged_call_to_a_hidden_action_runs_nothing(): void
    {
        $match = $this->match();
        $participant = $this->participant(ParticipantStatus::Confirmed);
        $this->actingAs($this->userWith([self::UPDATE]));

        // What the browser would send if it ignored that the buttons are not there
        $this->matchesManager($match)
            ->call('mountAction', 'reportResult', [], ['table' => true, 'recordKey' => $match->id])
            ->assertSet('mountedActions', []);
        $this->participantsManager()
            ->call('mountAction', 'check_in', [], ['table' => true, 'recordKey' => $participant->id])
            ->assertSet('mountedActions', [])
            ->call('callMountedAction')
            ->assertNotNotified();

        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
        $this->assertSame(ParticipantStatus::Confirmed, $participant->refresh()->status);
    }

    /**
     * A panel user (editor) with an extra role holding exactly the given permission keys.
     *
     * @param  array<string>  $permissionKeys
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

    private function participant(ParticipantStatus $status): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $this->tournament->id,
            'guest_name' => 'Jugador '.Str::random(4),
            'guest_email' => Str::random(8).'@example.com',
            'status' => $status->value,
            'registered_at' => now(),
        ]);
    }

    private function match(): MatchModel
    {
        $round = RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => RoundStatus::InProgress->value,
            'started_at' => now(),
        ]);

        return MatchModel::create([
            'id' => (string) Str::uuid(),
            'round_id' => $round->id,
            'player_1_id' => $this->participant(ParticipantStatus::Confirmed)->id,
            'player_2_id' => $this->participant(ParticipantStatus::Confirmed)->id,
            'result' => MatchResult::NotPlayed->value,
            'table_number' => 1,
        ])->refresh();
    }

    /**
     * @return Testable<ParticipantsRelationManager>
     */
    private function participantsManager(): Testable
    {
        return Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $this->tournament, 'pageClass' => EditTournament::class]);
    }

    /**
     * @return Testable<RoundsRelationManager>
     */
    private function roundsManager(): Testable
    {
        return Livewire::test(RoundsRelationManager::class, ['ownerRecord' => $this->tournament, 'pageClass' => EditTournament::class]);
    }

    /**
     * @return Testable<MatchesRelationManager>
     */
    private function matchesManager(MatchModel $match): Testable
    {
        return Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $match->round, 'pageClass' => EditTournament::class]);
    }
}
