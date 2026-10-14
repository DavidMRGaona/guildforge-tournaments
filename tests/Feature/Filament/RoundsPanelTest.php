<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * "Generar ronda" pairs the players and recording results updates the standings.
 */
final class RoundsPanelTest extends ModuleTestCase
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

    public function test_generate_round_creates_the_round_and_its_matches(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);

        $this->roundsManager($tournament)
            ->callTableAction('generateRound')
            ->assertNotified(__('tournaments::messages.messages.round_generated', ['number' => 1]));

        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $this->assertSame(2, MatchModel::query()->where('round_id', $round->id)->count());
    }

    public function test_generate_round_refuses_with_fewer_than_two_participants(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 1);

        $this->roundsManager($tournament)
            ->callTableAction('generateRound')
            ->assertNotified(__('tournaments::messages.errors.not_enough_players_for_round'));

        $this->assertSame(0, RoundModel::query()->count());
    }

    public function test_generate_round_refuses_while_the_previous_round_has_matches_without_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $this->roundsManager($tournament)->callTableAction('generateRound');

        $this->roundsManager($tournament)
            ->callTableAction('generateRound')
            ->assertNotified(__('tournaments::messages.errors.previous_round_not_completed'));

        $this->assertSame(1, RoundModel::query()->count());
    }

    public function test_results_saved_from_manage_matches_update_the_standings(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        [$played, $pending] = MatchModel::query()->where('round_id', $round->id)->orderBy('table_number')->get()->all();

        $this->roundsManager($tournament)
            ->mountTableAction('manageMatches', $round)
            ->setTableActionData(['matches' => [
                $this->matchRow($played, MatchResult::PlayerTwoWin),
                $this->matchRow($pending, MatchResult::NotPlayed),
            ]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tournaments::messages.messages.results_updated'));

        $this->assertSame(MatchResult::PlayerTwoWin, $played->refresh()->result);
        $this->assertSame(MatchResult::NotPlayed, $pending->refresh()->result);
        $winner = StandingModel::query()->where('participant_id', $played->player_2_id)->sole();
        $this->assertSame([1, 1], [$winner->rank, $winner->wins]);
        $this->assertEquals(3.0, $winner->points);
    }

    public function test_report_result_on_a_match_updates_the_standings(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $round, 'pageClass' => EditTournament::class])
            ->callTableAction('reportResult', $match, data: ['result' => MatchResult::Draw->value])
            ->assertHasNoTableActionErrors();

        $this->assertSame(MatchResult::Draw, $match->refresh()->result);
        $this->assertSame(1, StandingModel::query()->where('participant_id', $match->player_1_id)->sole()->draws);
    }

    private function roundsManager(TournamentModel $tournament): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(RoundsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchRow(MatchModel $match, MatchResult $result): array
    {
        return [
            'match_id' => $match->id,
            'table_number' => $match->table_number,
            'player1_name' => 'Jugador 1',
            'player2_name' => 'Jugador 2',
            'result' => $result->value,
            'player_1_stats' => [],
            'player_2_stats' => [],
            'is_bye' => false,
        ];
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo suizo',
            'slug' => 'torneo-suizo',
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => 'admin_only',
        ])->refresh();
    }

    private function participants(TournamentModel $tournament, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'guest_name' => 'Jugador '.($i + 1),
                'guest_email' => "jugador{$i}@example.com",
                'status' => ParticipantStatus::Confirmed->value,
                'registered_at' => now(),
            ]);
        }
    }
}
