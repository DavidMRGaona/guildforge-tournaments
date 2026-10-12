<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Pages\ModuleSettingsPage;
use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\CreateGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\EditGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\ListGameProfiles;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class TournamentsPanelTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_game_profile_pages_render(): void
    {
        $profile = $this->systemProfile();

        Livewire::test(ListGameProfiles::class)->assertOk()->assertCanSeeTableRecords([$profile]);
        Livewire::test(CreateGameProfile::class)->assertOk();
        Livewire::test(EditGameProfile::class, ['record' => $profile->getRouteKey()])->assertOk();
    }

    public function test_system_game_profile_cannot_be_deleted_from_the_edit_page(): void
    {
        $profile = $this->systemProfile();

        Livewire::test(EditGameProfile::class, ['record' => $profile->getRouteKey()])
            ->assertActionHidden('delete');
        // The page hides its action on its own: the panel-wide rule is the policy's
        $this->assertFalse(GameProfileResource::canDelete($profile));
    }

    public function test_bulk_delete_keeps_system_game_profiles(): void
    {
        $profile = $this->systemProfile();

        Livewire::test(ListGameProfiles::class)->callTableBulkAction('delete', [$profile]);

        $this->assertNotNull(GameProfileModel::query()->find($profile->getKey()));
    }

    public function test_system_game_profiles_cannot_be_selected_for_bulk_actions(): void
    {
        $table = Livewire::test(ListGameProfiles::class)->instance()->getTable();

        $this->assertFalse($table->isRecordSelectable($this->systemProfile()));
    }

    public function test_bulk_delete_removes_the_other_profiles_when_a_system_one_is_selected(): void
    {
        $system = $this->systemProfile();
        $custom = $system->replicate(['id', 'slug']);
        $custom->name = 'Perfil propio';
        $custom->is_system = false;
        $custom->save();

        Livewire::test(ListGameProfiles::class)->callTableBulkAction('delete', [$system, $custom]);

        $this->assertNotNull(GameProfileModel::query()->find($system->getKey()));
        $this->assertNull(GameProfileModel::query()->find($custom->getKey()));
    }

    public function test_a_game_profile_is_duplicated_from_the_list(): void
    {
        $profile = $this->systemProfile();

        // The list loads each profile with its tournaments count, which is not a column
        Livewire::test(ListGameProfiles::class)
            ->callTableAction('duplicate', $profile, data: ['name' => 'Copia del perfil'])
            ->assertHasNoErrors();

        $copy = GameProfileModel::query()->where('name', 'Copia del perfil')->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_system);
    }

    public function test_game_profile_forms_show_their_notices(): void
    {
        Livewire::test(EditGameProfile::class, ['record' => $this->systemProfile()->getRouteKey()])
            ->assertSee(__('tournaments::messages.game_profile.system_warning'));
        Livewire::test(CreateGameProfile::class)
            ->assertSee(__('tournaments::messages.stat_definitions.help'))
            ->assertSee(__('tournaments::messages.scoring_rules.help'))
            ->assertSee(__('tournaments::messages.tiebreaker_config.help'));
    }

    public function test_tournament_pages_render(): void
    {
        $tournament = $this->tournament();

        Livewire::test(ListTournaments::class)->assertOk()->assertCanSeeTableRecords([$tournament]);
        Livewire::test(CreateTournament::class)->assertOk();
        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->assertOk()
            ->assertFormSet(['name' => 'Torneo de prueba'])
            ->assertDontSee(__('tournaments::messages.advanced_config.inherit_from_profile'));
    }

    public function test_edit_page_shows_that_the_configuration_comes_from_the_game_profile(): void
    {
        $tournament = $this->tournament(['game_profile_id' => $this->systemProfile()->id]);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->assertOk()
            ->assertSee(__('tournaments::messages.advanced_config.inherit_from_profile'));
    }

    public function test_a_tournament_is_created_from_a_seeded_system_profile(): void
    {
        $profile = $this->systemProfile();

        Livewire::test(CreateTournament::class)
            ->fillForm([
                'event_id' => EventModel::factory()->create(['is_published' => true])->id,
                'name' => 'Torneo de prueba',
                'notification_email' => 'torneos@example.com',
                'game_profile_id' => $profile->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tournament = TournamentModel::query()->where('name', 'Torneo de prueba')->firstOrFail();
        $this->assertSame($profile->id, $tournament->game_profile_id);
        $this->assertSame('result', $tournament->scoring_rules[0]['condition']['type'] ?? null);
    }

    public function test_tournament_relation_managers_render(): void
    {
        $tournament = $this->tournament();
        $round = RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);

        foreach ([ParticipantsRelationManager::class, RoundsRelationManager::class] as $relationManager) {
            Livewire::test($relationManager, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
                ->assertOk();
        }

        Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $round, 'pageClass' => EditTournament::class])
            ->assertOk();
    }

    public function test_event_config_relation_manager_renders_and_saves(): void
    {
        $event = EventModel::factory()->create();

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->assertOk()
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(ModuleSettingsPage::class, ['module' => 'tournaments'])->assertOk();
    }

    private function systemProfile(): GameProfileModel
    {
        return GameProfileModel::query()->where('is_system', true)->firstOrFail();
    }

    /**
     * Reloaded so the columns left to their database defaults are set, as on a real request.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-de-prueba',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
            ...$attributes,
        ])->refresh();
    }
}
