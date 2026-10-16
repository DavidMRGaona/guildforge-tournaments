<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The points and tiebreakers of the event's tournament tab are the tournament's own rules.
 */
final class EventTournamentTabTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPanelAdmin();
    }

    public function test_the_points_and_tiebreakers_are_saved_as_the_tournament_rules(): void
    {
        $event = EventModel::factory()->create();

        $this->tab($event)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo del evento')
            ->set('configData.score_weights', [
                ['name' => 'Victoria', 'key' => 'win', 'points' => 2],
                ['name' => 'Empate', 'key' => 'draw', 'points' => 1],
                ['name' => 'Derrota', 'key' => 'loss', 'points' => 0],
            ])
            ->set('configData.tiebreakers', ['opponent_win_percentage', 'median_buchholz'])
            ->call('save')
            ->assertHasNoErrors();

        $tournament = TournamentModel::query()->where('event_id', $event->id)->sole();
        $this->assertSame(
            [['win', 2.0], ['draw', 1.0], ['loss', 0.0]],
            array_map(static fn (array $rule): array => [$rule['condition']['result_value'], (float) $rule['points']], $tournament->scoring_rules ?? [])
        );
        $this->assertSame('result', data_get($tournament->scoring_rules, '0.condition.type'));
        $this->assertSame(['owp', 'median_buchholz'], array_column($tournament->tiebreaker_config ?? [], 'type'));
    }

    public function test_the_tab_shows_the_tournament_rules(): void
    {
        $tournament = $this->tournament([
            'scoring_rules' => [$this->resultRule('Victoria', 'win', 4), $this->resultRule('Bye', 'bye', 2)],
            'tiebreaker_config' => [['key' => 'owp', 'name' => 'OWP', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null]],
        ]);

        $tab = $this->tab($tournament->event);

        $this->assertSame(
            [['Victoria', 'win', 4.0], ['Bye', 'bye', 2.0]],
            array_map(static fn (array $weight): array => [$weight['name'], $weight['key'], (float) $weight['points']], array_values($tab->get('configData.score_weights')))
        );
        $tab->assertSet('configData.tiebreakers', ['opponent_win_percentage']);
    }

    public function test_advanced_rules_are_kept_when_the_tab_is_saved(): void
    {
        $advancedRules = [
            ['name' => 'Victoria amplia', 'points' => 5, 'priority' => 10, 'condition' => ['type' => 'margin_diff', 'result_value' => null, 'stat' => 'vp', 'operator' => '>=', 'value' => 20]],
            $this->resultRule('Victoria', 'win', 3),
        ];
        $advancedTiebreakers = [['key' => 'vp', 'name' => 'VP', 'type' => 'stat_sum', 'stat' => 'vp', 'direction' => 'desc', 'min_value' => null]];
        $tournament = $this->tournament(['scoring_rules' => $advancedRules, 'tiebreaker_config' => $advancedTiebreakers]);

        $this->tab($tournament->event)
            ->assertSee(__('tournaments::messages.config.advanced_rules_notice'))
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame('Nuevo nombre', $tournament->name);
        $this->assertEquals($advancedRules, $tournament->scoring_rules);
        $this->assertEquals($advancedTiebreakers, $tournament->tiebreaker_config);
    }

    public function test_a_client_cannot_clear_the_advanced_rules_flag_to_overwrite_them(): void
    {
        $advancedRules = [
            ['name' => 'Victoria amplia', 'points' => 5, 'priority' => 10, 'condition' => ['type' => 'margin_diff', 'result_value' => null, 'stat' => 'vp', 'operator' => '>=', 'value' => 20]],
            $this->resultRule('Victoria', 'win', 3),
        ];
        $advancedTiebreakers = [['key' => 'vp', 'name' => 'VP', 'type' => 'stat_sum', 'stat' => 'vp', 'direction' => 'desc', 'min_value' => null]];
        $tournament = $this->tournament(['scoring_rules' => $advancedRules, 'tiebreaker_config' => $advancedTiebreakers]);

        $tab = $this->tab($tournament->event)->assertSet('hasAdvancedRules', true);

        try {
            $tab->set('hasAdvancedRules', false);
        } catch (CannotUpdateLockedPropertyException) {
            // The forged update is rejected; the component keeps its state
        }

        $tab->assertSet('hasAdvancedRules', true)
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame('Nuevo nombre', $tournament->name);
        $this->assertEquals($advancedRules, $tournament->scoring_rules);
        $this->assertEquals($advancedTiebreakers, $tournament->tiebreaker_config);
    }

    public function test_a_floored_tiebreaker_round_trips_unchanged(): void
    {
        $rules = [$this->resultRule('Victoria', 'win', 3)];
        $floored = [['key' => 'owp', 'name' => 'OMW%', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => 0.33]];
        $tournament = $this->tournament(['scoring_rules' => $rules, 'tiebreaker_config' => $floored]);

        $this->tab($tournament->event)
            ->assertSee(__('tournaments::messages.config.advanced_rules_notice'))
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals($floored, $tournament->refresh()->tiebreaker_config);
        $this->assertEquals($rules, $tournament->scoring_rules);
    }

    public function test_a_tiebreaker_with_a_stat_round_trips_unchanged(): void
    {
        $withStat = [['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => 'vp', 'direction' => 'desc', 'min_value' => null]];
        $tournament = $this->tournament(['scoring_rules' => [$this->resultRule('Victoria', 'win', 3)], 'tiebreaker_config' => $withStat]);

        $this->tab($tournament->event)->set('configData.name', 'Nuevo nombre')->call('save')->assertHasNoErrors();

        $this->assertEquals($withStat, $tournament->refresh()->tiebreaker_config);
    }

    public function test_a_tournament_without_profile_or_rules_saves_the_default_rules(): void
    {
        $tournament = $this->tournament([]);

        $this->tab($tournament->event)
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame([['win', 3.0], ['draw', 1.0], ['loss', 0.0], ['bye', 3.0]], $this->pointsByResult($tournament->scoring_rules));
        $this->assertSame(['buchholz', 'progressive'], array_column($tournament->tiebreaker_config ?? [], 'type'));
    }

    public function test_a_tournament_with_profile_and_own_rules_shows_and_saves_its_own_rules(): void
    {
        $tournament = $this->tournament([
            'game_profile_id' => $this->profile()->id,
            'scoring_rules' => [$this->resultRule('Victoria', 'win', 4), $this->resultRule('Derrota', 'loss', 1)],
            'tiebreaker_config' => [['key' => 'owp', 'name' => 'OWP', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null]],
        ]);

        $tab = $this->tab($tournament->event);
        $this->assertSame([['win', 4.0], ['loss', 1.0]], $this->weightsByResult($tab->get('configData.score_weights')));
        $tab->assertSet('configData.tiebreakers', ['opponent_win_percentage'])
            ->set('configData.score_weights', [
                ['name' => 'Victoria', 'key' => 'win', 'points' => 5],
                ['name' => 'Derrota', 'key' => 'loss', 'points' => 1],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame([['win', 5.0], ['loss', 1.0]], $this->pointsByResult($tournament->scoring_rules));
        $this->assertSame(['owp'], array_column($tournament->tiebreaker_config ?? [], 'type'));
    }

    public function test_a_tournament_without_own_rules_shows_its_profile_rules(): void
    {
        $profile = $this->profile();
        $tournament = $this->tournament(['game_profile_id' => $profile->id]);

        $tab = $this->tab($tournament->event);

        $this->assertSame([['win', 2.0], ['draw', 1.0], ['loss', 0.0], ['bye', 2.0]], $this->weightsByResult($tab->get('configData.score_weights')));
        $tab->assertSet('configData.tiebreakers', ['median_buchholz', 'opponent_win_percentage'])
            ->assertSee(__('tournaments::messages.config.profile_rules_notice', ['profile' => 'Perfil de la liga']))
            ->assertDontSee(__('tournaments::messages.config.advanced_rules_notice'));
    }

    public function test_empty_own_rules_count_as_none_like_in_the_standings(): void
    {
        $tournament = $this->tournament(['game_profile_id' => $this->profile()->id, 'scoring_rules' => [], 'tiebreaker_config' => []]);

        $tab = $this->tab($tournament->event)->assertSet('hasAdvancedRules', false);

        $this->assertSame([['win', 2.0], ['draw', 1.0], ['loss', 0.0], ['bye', 2.0]], $this->weightsByResult($tab->get('configData.score_weights')));
        $tab->assertSet('configData.tiebreakers', ['median_buchholz', 'opponent_win_percentage']);
    }

    public function test_saving_the_tab_without_touching_the_rules_keeps_the_profile_rules(): void
    {
        $tournament = $this->tournament(['game_profile_id' => $this->profile()->id]);

        $this->tab($tournament->event)
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame('Nuevo nombre', $tournament->name);
        $this->assertNull($tournament->scoring_rules);
        $this->assertNull($tournament->tiebreaker_config);
    }

    public function test_only_the_rules_changed_in_the_tab_become_the_tournament_rules(): void
    {
        $tournament = $this->tournament(['game_profile_id' => $this->profile()->id]);

        $this->tab($tournament->event)
            ->set('configData.score_weights', [
                ['name' => 'Victoria', 'key' => 'win', 'points' => 3],
                ['name' => 'Empate', 'key' => 'draw', 'points' => 1],
                ['name' => 'Derrota', 'key' => 'loss', 'points' => 0],
                ['name' => 'Bye', 'key' => 'bye', 'points' => 2],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame([['win', 3.0], ['draw', 1.0], ['loss', 0.0], ['bye', 2.0]], $this->pointsByResult($tournament->scoring_rules));
        $this->assertNull($tournament->tiebreaker_config);
    }

    public function test_changed_tiebreakers_alone_become_the_tournament_tiebreakers(): void
    {
        $tournament = $this->tournament(['game_profile_id' => $this->profile()->id]);

        $this->tab($tournament->event)
            ->set('configData.tiebreakers', ['buchholz'])
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertNull($tournament->scoring_rules);
        $this->assertSame(['buchholz'], array_column($tournament->tiebreaker_config ?? [], 'type'));
    }

    public function test_advanced_profile_rules_are_shown_as_such_and_never_copied(): void
    {
        $profile = $this->profile([
            'scoring_rules' => [
                ['name' => 'Victoria amplia', 'points' => 5, 'priority' => 10, 'condition' => ['type' => 'margin_diff', 'result_value' => null, 'stat' => 'vp', 'operator' => '>=', 'value' => 20]],
                $this->resultRule('Victoria', 'win', 3),
            ],
        ]);
        $tournament = $this->tournament(['game_profile_id' => $profile->id]);

        $this->tab($tournament->event)
            ->assertSet('hasAdvancedRules', true)
            ->assertSee(__('tournaments::messages.config.advanced_rules_notice'))
            ->set('configData.name', 'Nuevo nombre')
            ->call('save')
            ->assertHasNoErrors();

        $tournament->refresh();
        $this->assertSame('Nuevo nombre', $tournament->name);
        $this->assertNull($tournament->scoring_rules);
        $this->assertNull($tournament->tiebreaker_config);
    }

    /**
     * @param  array<mixed>|null  $rules
     * @return list<array{string, float}>
     */
    private function pointsByResult(?array $rules): array
    {
        return array_values(array_map(static fn (array $rule): array => [$rule['condition']['result_value'], (float) $rule['points']], $rules ?? []));
    }

    /**
     * @param  array<mixed>  $weights
     * @return list<array{string, float}>
     */
    private function weightsByResult(array $weights): array
    {
        return array_values(array_map(static fn (array $weight): array => [$weight['key'], (float) $weight['points']], $weights));
    }

    /**
     * A custom game profile: 2/1/0/2 points, median Buchholz then OWP.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes = []): GameProfileModel
    {
        return GameProfileModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'Perfil de la liga',
            'slug' => 'perfil-de-la-liga-'.Str::random(6),
            'stat_definitions' => [],
            'scoring_rules' => [
                $this->resultRule('Victoria', 'win', 2),
                $this->resultRule('Empate', 'draw', 1),
                $this->resultRule('Derrota', 'loss', 0),
                $this->resultRule('Bye', 'bye', 2),
            ],
            'tiebreaker_config' => [
                ['key' => 'median_buchholz', 'name' => 'Buchholz mediano', 'type' => 'median_buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                ['key' => 'owp', 'name' => 'OWP', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
            ],
            'pairing_config' => [],
            'is_system' => false,
            ...$attributes,
        ]);
    }

    public function test_the_registration_cannot_close_after_the_event_starts(): void
    {
        $event = EventModel::factory()->create(['start_date' => '2036-11-20 10:00:00']);

        $tab = $this->tab($event)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo del evento')
            ->set('configData.registration_closes_at', '2036-11-20 10:30:00')
            ->call('save')
            ->assertHasErrors(['configData.registration_closes_at']);

        $this->assertContains(
            'El cierre de inscripción no puede ser posterior al inicio del evento (20/11/2036 10:00)',
            $tab->errors()->get('configData.registration_closes_at'),
        );
        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    public function test_the_registration_can_close_when_the_event_starts(): void
    {
        $event = EventModel::factory()->create(['start_date' => '2036-11-20 10:00:00']);

        $this->tab($event)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo del evento')
            ->set('configData.registration_closes_at', '2036-11-20 10:00:00')
            ->call('save')
            ->assertHasNoErrors();

        $tournament = TournamentModel::query()->where('event_id', $event->id)->sole();
        $this->assertSame('2036-11-20 10:00:00', $tournament->registration_closes_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_garbage_closing_date_never_throws(): void
    {
        $event = EventModel::factory()->create(['start_date' => '2036-11-20 10:00:00']);

        $this->tab($event)
            ->set('configData.tournament_enabled', true)
            ->set('configData.name', 'Torneo del evento')
            ->set('configData.registration_closes_at', 'definitely not a date')
            ->call('save')
            ->assertHasErrors(['configData.registration_closes_at']);

        $this->assertFalse(TournamentModel::query()->where('event_id', $event->id)->exists());
    }

    /**
     * @return Testable<TournamentConfigRelationManager>
     */
    private function tab(EventModel $event): Testable
    {
        return Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultRule(string $name, string $result, int $points): array
    {
        return ['name' => $name, 'points' => $points, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => $result, 'stat' => null, 'operator' => null, 'value' => null]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo del evento',
            'slug' => 'torneo-del-evento',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
            ...$attributes,
        ])->refresh();
    }
}
