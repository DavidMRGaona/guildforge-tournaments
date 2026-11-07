<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The event's tournament tab follows the tournaments permissions, not the event's:
 * seeing it needs tournaments.view_any, and saving it needs tournaments.create, update
 * or delete for what the save does, checked on the server. Switching off a tournament
 * that has participants deletes them too, so it asks for a second confirmation.
 */
final class EventTournamentTabPermissionsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string EVENTS_UPDATE = 'events.update';

    private const string VIEW_ANY = 'tournaments:tournaments.view_any';

    private const string CREATE = 'tournaments:tournaments.create';

    private const string UPDATE = 'tournaments:tournaments.update';

    private const string DELETE = 'tournaments:tournaments.delete';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_tab_is_hidden_without_view_any(): void
    {
        $event = EventModel::factory()->create();

        $this->actingAs($this->userWith([self::EVENTS_UPDATE]));
        $this->assertFalse(TournamentConfigRelationManager::canViewForRecord($event, EditEvent::class));

        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY]));
        $this->assertTrue(TournamentConfigRelationManager::canViewForRecord($event, EditEvent::class));
    }

    public function test_editing_events_alone_cannot_open_the_tab_to_create_change_or_disable_a_tournament(): void
    {
        $this->actingAs($this->userWith([self::EVENTS_UPDATE]));
        $event = EventModel::factory()->create();
        $tournament = $this->tournament();

        // Filament checks canViewForRecord() on every request of the tab, so no save can follow
        $this->tab($event)->assertForbidden();
        $this->tab($tournament->event)->assertForbidden();

        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
        $this->assertSame('Torneo del evento', $tournament->refresh()->name);
    }

    public function test_viewing_alone_shows_the_tab_without_its_save_button(): void
    {
        $tournament = $this->tournament();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY]));

        $this->tab($tournament->event)
            ->assertOk()
            ->assertFormFieldIsDisabled('tournament_enabled', 'configForm')
            ->assertDontSee(__('tournaments::messages.config.save'));

        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::UPDATE]));

        $this->tab($tournament->event)->assertSee(__('tournaments::messages.config.save'));
    }

    public function test_creating_a_tournament_needs_create(): void
    {
        $event = EventModel::factory()->create();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::UPDATE, self::DELETE]));

        $this->tab($event)
            ->assertFormFieldIsDisabled('tournament_enabled', 'configForm')
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo no autorizado')
            ->call('save')
            ->assertForbidden();

        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    public function test_create_allows_creating_a_tournament(): void
    {
        $event = EventModel::factory()->create();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::CREATE]));

        $this->tab($event)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo nuevo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Torneo nuevo', TournamentModel::query()->where('event_id', $event->id)->sole()->name);
    }

    public function test_changing_a_tournament_needs_update(): void
    {
        $tournament = $this->tournament();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::CREATE, self::DELETE]));

        $this->tab($tournament->event)
            ->set('configData.name', 'Nombre no autorizado')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Torneo del evento', $tournament->refresh()->name);
    }

    public function test_update_allows_changing_a_tournament(): void
    {
        $tournament = $this->tournament();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::UPDATE]));

        $this->tab($tournament->event)
            ->assertFormFieldIsDisabled('tournament_enabled', 'configForm')
            ->set('configData.name', 'Nombre nuevo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nombre nuevo', $tournament->refresh()->name);
    }

    public function test_disabling_a_tournament_needs_delete(): void
    {
        $tournament = $this->tournament();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::CREATE, self::UPDATE]));

        $this->tab($tournament->event)
            ->assertFormFieldIsDisabled('tournament_enabled', 'configForm')
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->assertForbidden();

        $this->assertNotNull(TournamentModel::query()->find($tournament->id));
    }

    public function test_delete_allows_disabling_a_tournament_without_participants(): void
    {
        $tournament = $this->tournament();
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::DELETE]));

        $this->tab($tournament->event)
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(TournamentModel::query()->find($tournament->id));
    }

    public function test_disabling_a_tournament_with_participants_asks_for_confirmation(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament);
        $this->actingAsPanelAdmin();

        $this->tab($tournament->event)
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->assertActionMounted('disableTournament');

        $this->assertNotNull(TournamentModel::query()->find($tournament->id));
    }

    public function test_the_confirmation_needs_its_checkbox(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament);
        $this->actingAsPanelAdmin();

        $this->tab($tournament->event)
            ->set('configData.tournament_enabled', false)
            ->callAction('disableTournament', data: ['confirmation' => false])
            ->assertHasActionErrors(['confirmation' => 'accepted']);

        $this->assertNotNull(TournamentModel::query()->find($tournament->id));
    }

    public function test_confirming_disables_the_tournament_with_its_participants(): void
    {
        $tournament = $this->tournament();
        $participant = $this->participant($tournament);
        $this->actingAsPanelAdmin();

        $this->tab($tournament->event)
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->setActionData(['confirmation' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertNull(TournamentModel::query()->find($tournament->id));
        $this->assertNull(ParticipantModel::query()->find($participant->id));
    }

    public function test_the_confirmation_needs_delete(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament);
        $this->actingAs($this->userWith([self::EVENTS_UPDATE, self::VIEW_ANY, self::CREATE, self::UPDATE]));

        $this->tab($tournament->event)
            ->assertActionHidden('disableTournament')
            ->call('mountAction', 'disableTournament')
            ->call('callMountedAction');

        $this->assertNotNull(TournamentModel::query()->find($tournament->id));
    }

    /**
     * @return Testable<TournamentConfigRelationManager>
     */
    private function tab(?EventModel $event): Testable
    {
        $this->assertNotNull($event);

        return Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo del evento',
            'slug' => 'torneo-del-evento-'.Str::lower(Str::random(6)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
        ])->refresh();
    }

    private function participant(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Jugador '.Str::random(4),
            'guest_email' => Str::random(8).'@example.com',
            'status' => ParticipantStatus::Registered->value,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $module = str_contains($key, ':') ? Str::before($key, ':') : null;
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => Str::before(Str::after($key, ':'), '.'), 'action' => Str::afterLast($key, '.'), 'module' => $module],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
