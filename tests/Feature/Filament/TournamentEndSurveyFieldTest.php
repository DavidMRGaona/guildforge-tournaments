<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Application\Surveys\Contracts\SubjectEndSurveyChoiceInterface;
use App\Application\Surveys\DTOs\EndSurveyChoiceDTO;
use App\Application\Surveys\DTOs\EndSurveyOptionDTO;
use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Surveys\FakeSubjectEndSurveyChoice;

/**
 * Both tournament forms (the resource and the tab on the event page) carry the core's "survey
 * when it ends" field for the tournament subject type, without knowing the surveys module.
 */
final class TournamentEndSurveyFieldTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_without_the_surveys_module_the_tournament_form_has_no_end_survey(): void
    {
        Livewire::test(EditTournament::class, ['record' => $this->tournament()->getRouteKey()])
            ->assertFormFieldIsHidden('end_survey');
    }

    public function test_the_tournament_form_chooses_the_end_survey_of_the_tournament(): void
    {
        $fake = $this->fake();
        $tournament = $this->tournament();

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->assertFormFieldIsVisible('end_survey')
            ->fillForm(['end_survey' => 'none'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([[TournamentSurveySubjectSource::TYPE, (string) $tournament->id, 'none']], $this->chosen($fake));
    }

    public function test_the_event_tab_chooses_it_for_an_existing_tournament(): void
    {
        $fake = $this->fake();
        $tournament = $this->tournament();

        $this->tab($tournament->event_id)
            ->set('configData.end_survey', 'none')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([[TournamentSurveySubjectSource::TYPE, (string) $tournament->id, 'none']], $this->chosen($fake));
    }

    public function test_the_event_tab_chooses_it_for_a_tournament_it_creates(): void
    {
        $fake = $this->fake();
        $event = EventModel::factory()->create();

        $this->tab($event->id)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo del evento')
            ->set('configData.end_survey', 'none')
            ->call('save')
            ->assertHasNoErrors();

        $tournament = TournamentModel::query()->where('event_id', $event->id)->sole();
        $this->assertSame([[TournamentSurveySubjectSource::TYPE, (string) $tournament->id, 'none']], $this->chosen($fake));
    }

    private function fake(): FakeSubjectEndSurveyChoice
    {
        $fake = new FakeSubjectEndSurveyChoice(new EndSurveyChoiceDTO(
            options: [new EndSurveyOptionDTO('type-default', 'Plantilla del tipo: Torneo'), new EndSurveyOptionDTO('none', 'Ninguna')],
            selected: 'type-default',
        ));
        $this->app->instance(SubjectEndSurveyChoiceInterface::class, $fake);

        return $fake;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function chosen(FakeSubjectEndSurveyChoice $fake): array
    {
        return array_map(static fn (array $call): array => [$call['sourceType'], $call['subjectId'], $call['selection']->value], $fake->chosen);
    }

    /**
     * @return Testable<TournamentConfigRelationManager>
     */
    private function tab(string $eventId): Testable
    {
        return Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => EventModel::query()->findOrFail($eventId), 'pageClass' => EditEvent::class]);
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => 'draft',
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ])->refresh();
    }
}
