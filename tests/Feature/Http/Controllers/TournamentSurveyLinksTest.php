<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Surveys\Contracts\SurveySubjectLinksInterface;
use App\Application\Surveys\DTOs\SurveyLinkDTO;
use App\Application\Surveys\DTOs\SurveySubjectLinksDTO;
use App\Application\Surveys\Enums\SurveyLinkAction;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use ArrayObject;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournament's page links to its surveys through the core's contract, whatever module answers
 * it: nothing changes without one.
 */
final class TournamentSurveyLinksTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    /** @var ArrayObject<int, array{string, string, ?string}> what the page asked the contract */
    private ArrayObject $asked;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asked = new ArrayObject;
    }

    public function test_without_a_surveys_module_the_page_has_no_survey_links(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);

        $this->actingAs(UserModel::factory()->create())->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('surveyLinks', null));
    }

    public function test_the_page_shows_what_the_contract_links_for_the_person(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $user = UserModel::factory()->create();
        $this->fakeLinks(new SurveySubjectLinksDTO(
            manageUrl: 'https://example.com/encuestas/sujeto/tournament/'.$tournament->id,
            surveys: [new SurveyLinkDTO('Valoración del torneo', 'https://example.com/encuestas/1', SurveyLinkAction::ViewResults)],
        ));

        $this->actingAs($user)->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('surveyLinks.manageUrl', 'https://example.com/encuestas/sujeto/tournament/'.$tournament->id)
                ->where('surveyLinks.surveys.0.title', 'Valoración del torneo')
                ->where('surveyLinks.surveys.0.url', 'https://example.com/encuestas/1')
                ->where('surveyLinks.surveys.0.action', 'view_results'));

        $this->assertSame([['tournament', $tournament->id, $user->id]], $this->asked->getArrayCopy());
    }

    public function test_links_show_whatever_the_state_of_the_tournament(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);
        $this->fakeLinks(new SurveySubjectLinksDTO(manageUrl: 'https://example.com/encuestas/sujeto/tournament/'.$tournament->id));

        $this->actingAs(UserModel::factory()->create())->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('surveyLinks.manageUrl', 'https://example.com/encuestas/sujeto/tournament/'.$tournament->id));
    }

    public function test_nothing_to_link_gives_no_links(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $this->fakeLinks(SurveySubjectLinksDTO::none());

        $this->actingAs(UserModel::factory()->create())->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('surveyLinks', null));
    }

    public function test_a_visitor_is_not_asked_about(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $this->fakeLinks(new SurveySubjectLinksDTO(manageUrl: 'https://example.com/x'));

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('surveyLinks', null));

        $this->assertSame([], $this->asked->getArrayCopy());
    }

    private function fakeLinks(SurveySubjectLinksDTO $links): void
    {
        $this->app->instance(SurveySubjectLinksInterface::class, new readonly class($links, $this->asked) implements SurveySubjectLinksInterface
        {
            /**
             * @param  ArrayObject<int, array{string, string, ?string}>  $asked
             */
            public function __construct(private SurveySubjectLinksDTO $links, private ArrayObject $asked) {}

            public function forSubject(string $sourceType, string $subjectId, ?string $userId): SurveySubjectLinksDTO
            {
                $this->asked[] = [$sourceType, $subjectId, $userId];

                return $this->links;
            }
        });
    }

    private function tournament(TournamentStatus $status): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->published()->create(['start_date' => now()->subDay()])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ]);
    }
}
