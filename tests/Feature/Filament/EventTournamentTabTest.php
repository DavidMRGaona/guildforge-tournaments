<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
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
        $this->assertSame('result', $tournament->scoring_rules[0]['condition']['type'] ?? null);
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
