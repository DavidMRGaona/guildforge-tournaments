<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Surveys;

use App\Domain\Surveys\Events\SurveySubjectCancelled;
use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A tournament deleted before it ended will never end: a surveys module, if any, hears it as a
 * cancellation (core event), whichever way it goes, including with its event.
 */
final class TournamentDeletionSignalsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SurveySubjectCancelled::class]);
    }

    public function test_deleting_an_unfinished_tournament_announces_the_cancellation(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);

        $tournament->delete();

        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->sourceType === 'tournament'
            && $event->subjectId === $tournament->id);
    }

    public function test_deleting_a_finished_or_cancelled_tournament_announces_nothing(): void
    {
        $this->tournament(TournamentStatus::Finished)->delete();
        $this->tournament(TournamentStatus::Cancelled)->delete();

        Event::assertNotDispatched(SurveySubjectCancelled::class);
    }

    public function test_deleting_the_event_announces_its_tournament_cancellation(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);

        $tournament->event?->delete();

        $this->assertNull(TournamentModel::query()->find($tournament->id));
        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->subjectId === $tournament->id);
    }

    public function test_deleting_an_event_without_a_tournament_announces_nothing(): void
    {
        EventModel::factory()->create()->delete();

        Event::assertNotDispatched(SurveySubjectCancelled::class);
    }

    public function test_switching_the_tournament_off_in_the_event_tab_announces_the_cancellation(): void
    {
        $this->actingAsPanelAdmin();
        $tournament = $this->tournament(TournamentStatus::Draft);

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $tournament->event, 'pageClass' => EditEvent::class])
            ->set('configData.tournament_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(TournamentModel::query()->find($tournament->id));
        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->subjectId === $tournament->id);
    }

    public function test_the_panel_delete_actions_announce_the_cancellation(): void
    {
        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
        $deleted = $this->tournament(TournamentStatus::RegistrationClosed);
        $bulkDeleted = $this->tournament(TournamentStatus::Draft);

        Livewire::test(EditTournament::class, ['record' => $deleted->getRouteKey()])->callAction(DeleteAction::class);
        Livewire::test(ListTournaments::class)->callTableBulkAction('delete', [$bulkDeleted]);

        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->subjectId === $deleted->id);
        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->subjectId === $bulkDeleted->id);
    }

    private function tournament(TournamentStatus $status): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ])->refresh();
    }
}
