<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Proposals;

use App\Application\Proposals\DTOs\ProposalRequestFieldDTO;
use App\Application\Proposals\Services\EventProposalRequestRegistryInterface;
use App\Application\Proposals\Services\ProposalRequestValueRule;
use App\Domain\Proposals\Enums\ProposalStatus;
use App\Domain\Proposals\Exceptions\ProposalRequestRefusedException;
use App\Domain\ValueObjects\EventId;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Proposals\TournamentProposalRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournament request in event proposals (core#581): its fields, the draft tournament it
 * creates on approval and the module's event selectors, which never offer an unapproved proposal.
 */
final class TournamentProposalRequestTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentProposalRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = app(TournamentProposalRequest::class);
    }

    public function test_the_module_registers_its_request_with_the_core(): void
    {
        $registry = app(EventProposalRequestRegistryInterface::class);

        $this->assertInstanceOf(TournamentProposalRequest::class, $registry->request('tournaments.tournament'));
    }

    public function test_the_request_asks_for_a_profile_and_places_and_nothing_else(): void
    {
        $profile = $this->profile('Juego de ejemplo');

        $fields = $this->request->fields();

        $this->assertSame(['game_profile', 'places'], array_map(static fn (ProposalRequestFieldDTO $field): string => $field->name, $fields));
        $this->assertSame('tournaments.tournament', $this->request->key());
        $this->assertSame('Juego de ejemplo', $fields[0]->options[$profile->id] ?? null);
        $this->assertFalse($fields[0]->required);
        $this->assertFalse($fields[1]->required);
    }

    public function test_the_profile_options_follow_the_module_records_each_time(): void
    {
        $this->assertNotContains('Perfil nuevo', $this->request->fields()[0]->options);

        $profile = $this->profile('Perfil nuevo');

        $this->assertSame('Perfil nuevo', $this->request->fields()[0]->options[$profile->id] ?? null);
    }

    public function test_the_values_are_checked_by_the_core_rule(): void
    {
        $profile = $this->profile('Juego de ejemplo');
        $rule = app(ProposalRequestValueRule::class);

        $valid = $rule->check($this->request->fields(), ['game_profile' => $profile->id, 'places' => 16]);
        $this->assertTrue($valid->isValid());
        $this->assertSame(['game_profile' => $profile->id, 'places' => 16], $valid->values);

        $empty = $rule->check($this->request->fields(), []);
        $this->assertTrue($empty->isValid());
        $this->assertSame(['game_profile' => null, 'places' => null], $empty->values);

        $this->assertFalse($rule->check($this->request->fields(), ['game_profile' => (string) Str::uuid()])->isValid());
        $this->assertFalse($rule->check($this->request->fields(), ['places' => 1])->isValid());
    }

    public function test_apply_creates_a_draft_named_after_the_event_with_the_profile_and_places(): void
    {
        $profile = $this->profile('Juego de ejemplo', PairingMethod::Random);
        $event = EventModel::factory()->create(['title' => 'Gran torneo de otoño']);

        $this->request->apply(new EventId($event->id), ['game_profile' => $profile->id, 'places' => 24]);

        $tournament = TournamentModel::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('Gran torneo de otoño', $tournament->name);
        $this->assertStringStartsWith('gran-torneo-de-otono', $tournament->slug);
        $this->assertSame(TournamentStatus::Draft, $tournament->status);
        $this->assertSame($profile->id, $tournament->game_profile_id);
        $this->assertSame(24, $tournament->max_participants);
        $this->assertSame(2, $tournament->min_participants);
        $this->assertSame(PairingMethod::Random->value, $tournament->pairing_config['method'] ?? null);
        $this->assertEqualsCanonicalizing($profile->scoring_rules, $tournament->scoring_rules);
    }

    public function test_apply_without_profile_or_places_leaves_the_module_defaults(): void
    {
        $event = EventModel::factory()->create(['title' => 'Torneo abierto']);

        $this->request->apply(new EventId($event->id), ['game_profile' => null, 'places' => null]);

        $tournament = TournamentModel::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TournamentStatus::Draft, $tournament->status);
        $this->assertNull($tournament->game_profile_id);
        $this->assertNull($tournament->max_participants);
        $this->assertNotEmpty($tournament->scoring_rules);
        $this->assertNotEmpty($tournament->tiebreaker_config);
        $this->assertSame(PairingMethod::Swiss->value, $tournament->pairing_config['method'] ?? null);
    }

    public function test_events_with_the_same_title_get_different_slugs(): void
    {
        $first = EventModel::factory()->create(['title' => 'Torneo repetido']);
        $second = EventModel::factory()->create(['title' => 'Torneo repetido']);

        $this->request->apply(new EventId($first->id), ['game_profile' => null, 'places' => null]);
        $this->request->apply(new EventId($second->id), ['game_profile' => null, 'places' => null]);

        $slugs = TournamentModel::query()->pluck('slug')->all();
        $this->assertCount(2, array_unique($slugs));
    }

    public function test_apply_refuses_when_the_event_already_has_a_tournament(): void
    {
        $event = EventModel::factory()->create();
        $this->request->apply(new EventId($event->id), ['game_profile' => null, 'places' => 8]);

        try {
            $this->request->apply(new EventId($event->id), ['game_profile' => null, 'places' => 30]);
            $this->fail('A second tournament for the event must be refused.');
        } catch (ProposalRequestRefusedException $exception) {
            $this->assertSame(__('tournaments::messages.proposal_request.already_configured'), $exception->getMessage());
        }

        $this->assertSame(1, TournamentModel::query()->where('event_id', $event->id)->count());
        $this->assertSame(8, TournamentModel::query()->where('event_id', $event->id)->value('max_participants'));
    }

    public function test_apply_refuses_a_profile_that_no_longer_exists(): void
    {
        $event = EventModel::factory()->create();

        $this->expectException(ProposalRequestRefusedException::class);

        try {
            $this->request->apply(new EventId($event->id), ['game_profile' => (string) Str::uuid(), 'places' => null]);
        } finally {
            $this->assertSame(0, TournamentModel::query()->count());
        }
    }

    public function test_describe_reads_as_one_line(): void
    {
        $profile = $this->profile('Juego de ejemplo');

        $this->assertSame(
            __('tournaments::messages.proposal_request.describe', ['profile' => 'Juego de ejemplo', 'places' => 12]),
            $this->request->describe(['game_profile' => $profile->id, 'places' => 12]),
        );
        $this->assertSame(
            __('tournaments::messages.proposal_request.describe_plain'),
            $this->request->describe(['game_profile' => null, 'places' => null]),
        );
        $this->assertStringNotContainsString("\n", $this->request->describe(['game_profile' => $profile->id, 'places' => 12]));
    }

    /**
     * @return array<string, array{ProposalStatus, bool}>
     */
    public static function unapprovedProposals(): array
    {
        return [
            'draft never sent' => [ProposalStatus::Draft, false],
            'draft sent back' => [ProposalStatus::Draft, true],
            'in review' => [ProposalStatus::PendingReview, true],
            'rejected' => [ProposalStatus::Rejected, true],
        ];
    }

    #[DataProvider('unapprovedProposals')]
    public function test_the_panel_selectors_never_offer_an_unapproved_proposal(ProposalStatus $status, bool $submitted): void
    {
        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();

        $real = EventModel::factory()->published()->create(['title' => 'Evento real']);
        $approved = EventModel::factory()->proposal(ProposalStatus::Approved)->create(['title' => 'Propuesta aprobada']);
        $proposal = EventModel::factory()->proposal($status, submitted: $submitted)->create(['title' => 'Propuesta sin aprobar']);
        // Even a stray published flag (written around the model) must not make it selectable
        EventModel::query()->includingUnsubmittedProposals()->whereKey($proposal->id)->update(['is_published' => true]);

        Livewire::test(CreateTournament::class)
            ->assertFormFieldExists('event_id', static function (Select $field) use ($real, $approved, $proposal): bool {
                $options = $field->getOptions();

                return array_key_exists($real->id, $options)
                    && array_key_exists($approved->id, $options)
                    && ! array_key_exists($proposal->id, $options);
            });

        $list = Livewire::test(ListTournaments::class)->instance();
        $this->assertInstanceOf(ListTournaments::class, $list);
        $filter = $list->getTable()->getFilter('event_id');
        $this->assertInstanceOf(SelectFilter::class, $filter);
        $options = $filter->getOptions();

        $this->assertArrayHasKey($real->id, $options);
        $this->assertArrayNotHasKey($proposal->id, $options);
    }

    private function profile(string $name, PairingMethod $method = PairingMethod::Swiss): GameProfileModel
    {
        return GameProfileModel::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'stat_definitions' => [],
            'scoring_rules' => [[
                'name' => 'Victoria',
                'points' => 3,
                'priority' => 5,
                'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null],
            ]],
            'tiebreaker_config' => [],
            'pairing_config' => ['method' => $method->value],
        ]);
    }
}
