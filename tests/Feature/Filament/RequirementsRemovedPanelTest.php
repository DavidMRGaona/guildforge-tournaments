<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Removing the last requirement of a tournament that has not started lets its pending
 * registrations play, from the tournament form and from the tournament tab of the event.
 */
final class RequirementsRemovedPanelTest extends ModuleTestCase
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

    public function test_turning_off_manual_confirmation_in_the_tournament_form_confirms_the_pending_ones(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $participant = $this->registered($tournament);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->fillForm(['requires_manual_confirmation' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ParticipantStatus::Confirmed, $participant->refresh()->status);
    }

    public function test_saving_with_a_requirement_left_confirms_nobody(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true, 'requires_check_in' => true]);
        $participant = $this->registered($tournament);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->fillForm(['requires_manual_confirmation' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ParticipantStatus::Registered, $participant->refresh()->status);
    }

    public function test_turning_off_the_check_in_in_the_event_tab_confirms_the_pending_ones(): void
    {
        $tournament = $this->tournament(['requires_check_in' => true]);
        $participant = $this->registered($tournament);

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $tournament->event, 'pageClass' => EditEvent::class])
            ->set('configData.requires_check_in', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(ParticipantStatus::Confirmed, $participant->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->published()->upcoming()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ])->refresh();
    }

    private function registered(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
        ]);
    }
}
