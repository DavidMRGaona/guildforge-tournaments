<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Pages\ModuleSettingsPage;
use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
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
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
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

    public function test_bulk_delete_asks_the_policy_for_each_profile(): void
    {
        // Filament already drops unselectable records; the policy is the second guard
        $action = Livewire::test(ListGameProfiles::class)->instance()->getTable()->getBulkAction('delete');

        $this->assertNotNull($action);
        $this->assertTrue($action->shouldAuthorizeIndividualRecords());
        $this->assertFalse($action->getIndividualRecordAuthorizationResponse($this->systemProfile())->allowed());
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

    public function test_the_stats_and_scoring_rule_repeaters_show_no_fallback_label(): void
    {
        // An empty label falls back to the field name, in English
        Livewire::test(CreateTournament::class)->assertDontSee('Stat definitions')->assertDontSee('Scoring rules');
        Livewire::test(CreateGameProfile::class)->assertDontSee('Stat definitions')->assertDontSee('Scoring rules');
    }

    public function test_the_tiebreaker_repeater_shows_no_fallback_label(): void
    {
        Livewire::test(CreateTournament::class)->assertDontSee('Tiebreaker config');
        Livewire::test(CreateGameProfile::class)->assertDontSee('Tiebreaker config');
    }

    public function test_the_system_profile_warning_has_no_fallback_label(): void
    {
        Livewire::test(EditGameProfile::class, ['record' => $this->systemProfile()->getRouteKey()])
            ->assertDontSee('System profile warning');
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

    public function test_a_tournament_stored_with_the_old_accelerated_method_must_choose_another_to_save(): void
    {
        $tournament = $this->tournament(['pairing_config' => ['method' => 'accelerated']]);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->call('save')
            ->assertHasFormErrors(['pairing_config.method']);

        $this->assertSame('accelerated', $tournament->refresh()->pairing_config['method'] ?? null);
    }

    public function test_a_profile_stored_with_the_old_accelerated_method_must_choose_another_to_save(): void
    {
        $profile = $this->systemProfile()->replicate(['id', 'slug']);
        $profile->name = 'Perfil antiguo';
        $profile->is_system = false;
        $profile->pairing_config = [...$profile->pairing_config, 'method' => 'accelerated'];
        $profile->save();

        Livewire::test(EditGameProfile::class, ['record' => $profile->getRouteKey()])
            ->call('save')
            ->assertHasFormErrors(['pairing_config.method']);

        $this->assertSame('accelerated', $profile->refresh()->pairing_config['method'] ?? null);
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

    public function test_registration_cannot_close_after_the_event_starts(): void
    {
        $event = EventModel::factory()->create(['is_published' => true, 'start_date' => '2036-11-20 10:00:00']);

        $component = Livewire::test(CreateTournament::class)
            ->fillForm($this->closingFormData($event->id, '2036-11-20 10:30:00'))
            ->call('create')
            ->assertHasFormErrors(['registration_closes_at']);

        $this->assertContains(
            'El cierre de inscripción no puede ser posterior al inicio del evento (20/11/2036 10:00)',
            $component->errors()->get('data.registration_closes_at'),
        );
        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    public function test_registration_can_close_when_the_event_starts(): void
    {
        $event = EventModel::factory()->create(['is_published' => true, 'start_date' => '2036-11-20 10:00:00']);

        Livewire::test(CreateTournament::class)
            ->fillForm($this->closingFormData($event->id, '2036-11-20 10:00:00'))
            ->call('create')
            ->assertHasNoFormErrors();

        $tournament = TournamentModel::query()->where('event_id', $event->id)->sole();
        $this->assertSame('2036-11-20 10:00:00', $tournament->registration_closes_at?->format('Y-m-d H:i:s'));
    }

    public function test_the_closing_date_is_not_checked_until_an_event_is_chosen(): void
    {
        Livewire::test(CreateTournament::class)
            ->fillForm([
                'name' => 'Torneo de prueba',
                'notification_email' => 'torneos@example.com',
                'registration_closes_at' => '2036-11-20 10:30:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['event_id' => 'required'])
            ->assertHasNoFormErrors(['registration_closes_at']);
    }

    public function test_a_garbage_event_id_never_breaks_the_closing_date_check(): void
    {
        Livewire::test(CreateTournament::class)
            ->fillForm($this->closingFormData('not-a-uuid', '2036-11-20 10:30:00'))
            ->call('create')
            ->assertHasFormErrors(['event_id'])
            ->assertHasNoFormErrors(['registration_closes_at']);
    }

    public function test_a_missing_event_never_breaks_the_closing_date_check(): void
    {
        Livewire::test(CreateTournament::class)
            ->fillForm($this->closingFormData((string) Str::uuid(), '2036-11-20 10:30:00'))
            ->call('create')
            ->assertHasFormErrors(['event_id'])
            ->assertHasNoFormErrors(['registration_closes_at']);
    }

    public function test_a_garbage_closing_date_is_reported_without_an_exception(): void
    {
        $event = EventModel::factory()->create(['is_published' => true, 'start_date' => '2036-11-20 10:00:00']);

        $component = Livewire::test(CreateTournament::class)
            ->fillForm($this->closingFormData($event->id, 'definitely not a date'))
            ->call('create');

        $component->assertHasNoErrors(['data.event_id']);
        $component->assertHasFormErrors(['registration_closes_at']);
        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    public function test_opening_registration_warns_when_the_opening_date_is_still_ahead(): void
    {
        $tournament = $this->tournamentWithEventStart('2036-11-20 10:00:00', [
            'registration_opens_at' => '2036-05-01 09:30:00',
        ]);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('openRegistration')
            ->assertNotified(
                Notification::make()
                    ->title('La web aún no admite inscripciones')
                    ->body('La inscripción no se abrirá en la web hasta el 01/05/2036 09:30, la fecha de apertura configurada.')
                    ->warning()
            );

        $this->assertSame('registration_open', $tournament->refresh()->status->value);
    }

    public function test_opening_registration_warns_when_the_closing_date_has_passed(): void
    {
        $tournament = $this->tournamentWithEventStart('2036-11-20 10:00:00', [
            'registration_closes_at' => now()->subDay(),
        ]);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('openRegistration')
            ->assertNotified(
                Notification::make()
                    ->title('La web aún no admite inscripciones')
                    ->body('La fecha de cierre de inscripción ya ha pasado.')
                    ->warning()
            );
    }

    public function test_opening_registration_warns_when_the_event_has_started(): void
    {
        $tournament = $this->tournamentWithEventStart(now()->subHour());

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('openRegistration')
            ->assertNotified(
                Notification::make()
                    ->title('La web aún no admite inscripciones')
                    ->body('El evento ya ha comenzado.')
                    ->warning()
            );
    }

    public function test_opening_registration_keeps_the_success_notification_when_it_warns(): void
    {
        $tournament = $this->tournamentWithEventStart(now()->subHour());

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('openRegistration')
            ->assertNotified('Inscripción abierta');
    }

    public function test_opening_registration_does_not_warn_when_the_web_will_accept_registrations(): void
    {
        $tournament = $this->tournamentWithEventStart('2036-11-20 10:00:00');

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('openRegistration')
            ->assertNotNotified('La web aún no admite inscripciones');

        $this->assertSame('registration_open', $tournament->refresh()->status->value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournamentWithEventStart(\DateTimeInterface|string $eventStartsAt, array $attributes = []): TournamentModel
    {
        return $this->tournament([
            'event_id' => EventModel::factory()->create(['start_date' => $eventStartsAt])->id,
            ...$attributes,
        ]);
    }

    public function test_staff_can_withdraw_a_participant_after_the_event_has_started(): void
    {
        $tournament = $this->tournament([
            'event_id' => EventModel::factory()->create(['start_date' => now()->subHour()])->id,
            'status' => 'registration_closed',
        ]);
        $participant = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
        ]);

        Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
            ->callTableAction('withdraw', $participant);

        $this->assertSame(ParticipantStatus::Withdrawn, $participant->fresh()?->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function closingFormData(string $eventId, string $closesAt): array
    {
        return [
            'event_id' => $eventId,
            'name' => 'Torneo de prueba',
            'notification_email' => 'torneos@example.com',
            'game_profile_id' => $this->systemProfile()->id,
            'registration_closes_at' => $closesAt,
        ];
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
