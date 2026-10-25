<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
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
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentRoundRepository;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Modules\Tournaments\Tests\Support\StaleRoundRepository;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

require_once dirname(__DIR__, 2).'/Support/StaleRoundRepository.php';

/**
 * "Generar ronda" pairs the players and recording results updates the standings.
 */
final class RoundsPanelTest extends ModuleTestCase
{
    use PinsTheClock;

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

    public function test_a_second_simultaneous_generate_round_shows_a_notice(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $this->app->instance(RoundRepositoryInterface::class, new StaleRoundRepository(new EloquentRoundRepository()));

        $this->roundsManager($tournament)
            ->callTableAction('generateRound')
            ->assertNotified(__('tournaments::messages.errors.round_already_generated'));

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
                [...$this->matchRow($played, MatchResult::PlayerTwoWin), 'player_1_score' => 0, 'player_2_score' => 2],
                $this->matchRow($pending, MatchResult::NotPlayed),
            ]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('tournaments::messages.messages.results_updated'));

        $this->assertSame(MatchResult::PlayerTwoWin, $played->refresh()->result);
        $this->assertSame([0, 2], [$played->player_1_score, $played->player_2_score]);
        $this->assertSame(MatchResult::NotPlayed, $pending->refresh()->result);
        $winner = StandingModel::query()->where('participant_id', $played->player_2_id)->sole();
        $this->assertSame([1, 1], [$winner->rank, $winner->wins]);
        $this->assertEquals(3.0, $winner->points);
    }

    public function test_manage_matches_explains_scores_that_contradict_the_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        $this->roundsManager($tournament)
            ->mountTableAction('manageMatches', $round)
            ->setTableActionData(['matches' => [
                [...$this->matchRow($match, MatchResult::PlayerOneWin), 'player_1_score' => 0, 'player_2_score' => 2],
            ]])
            ->callMountedTableAction()
            ->assertNotified(__('tournaments::messages.errors.scores_contradict_result'));

        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
    }

    public function test_manage_matches_rejects_a_forged_bye_on_a_match_between_two_players(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        // The hidden bye flag is client state: a forged request can turn it on to be offered "bye"
        $this->roundsManager($tournament)
            ->mountTableAction('manageMatches', $round)
            ->setTableActionData(['matches' => [
                [...$this->matchRow($match, MatchResult::Bye), 'is_bye' => true],
            ]])
            ->callMountedTableAction()
            ->assertNotified(__('tournaments::messages.errors.invalid_match_result'));

        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
    }

    public function test_report_result_on_a_match_updates_the_standings(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $round, 'pageClass' => EditTournament::class])
            ->callTableAction('reportResult', $match, data: ['result' => MatchResult::Draw->value, 'player_1_score' => 1, 'player_2_score' => 1])
            ->assertHasNoTableActionErrors();

        $this->assertSame(MatchResult::Draw, $match->refresh()->result);
        $this->assertSame([1, 1], [$match->player_1_score, $match->player_2_score]);
        $this->assertSame(1, StandingModel::query()->where('participant_id', $match->player_1_id)->sole()->draws);
    }

    public function test_report_result_explains_scores_that_contradict_the_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        Livewire::test(MatchesRelationManager::class, ['ownerRecord' => $round, 'pageClass' => EditTournament::class])
            ->callTableAction('reportResult', $match, data: ['result' => MatchResult::PlayerOneWin->value, 'player_1_score' => 0, 'player_2_score' => 2])
            ->assertNotified(__('tournaments::messages.errors.scores_contradict_result'));

        $this->assertSame(MatchResult::NotPlayed, $match->refresh()->result);
    }

    public function test_start_round_is_only_offered_for_a_pending_round(): void
    {
        $tournament = $this->tournament();
        $pending = $this->round($tournament, 1, RoundStatus::Pending);
        $inProgress = $this->round($tournament, 2, RoundStatus::InProgress);
        $finished = $this->round($tournament, 3, RoundStatus::Finished);

        $this->roundsManager($tournament)
            ->assertTableActionVisible('startRound', $pending)
            ->assertTableActionHidden('startRound', $inProgress)
            ->assertTableActionHidden('startRound', $finished);
    }

    public function test_start_round_puts_the_round_in_progress(): void
    {
        $tournament = $this->tournament();
        $round = $this->round($tournament, 1, RoundStatus::Pending);

        $this->roundsManager($tournament)
            ->callTableAction('startRound', $round)
            ->assertNotified(__('tournaments::messages.messages.round_started'));

        $round->refresh();
        $this->assertSame(RoundStatus::InProgress, $round->status);
        $this->assertNotNull($round->started_at);
        $this->assertLessThan(5, abs(now()->diffInSeconds($round->started_at)));
        $this->assertNull($round->completed_at);
    }

    public function test_a_second_start_round_click_changes_nothing(): void
    {
        $tournament = $this->tournament();
        $round = $this->round($tournament, 1, RoundStatus::Pending);
        // Both clicks open the confirmation while the round is still pending
        $first = $this->roundsManager($tournament)->mountTableAction('startRound', $round);
        $second = $this->roundsManager($tournament)->mountTableAction('startRound', $round);
        $first->callMountedTableAction();
        $startedAt = $round->refresh()->started_at?->toIso8601String();
        $this->travel(1)->minutes();
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);

        $second->callMountedTableAction()->assertNotNotified();

        $round->refresh();
        $this->assertSame(RoundStatus::InProgress, $round->status);
        $this->assertSame($startedAt, $round->started_at?->toIso8601String());
        $this->roundsManager($tournament)->assertTableActionHidden('startRound', $round);
    }

    public function test_complete_round_is_only_offered_for_a_round_in_progress(): void
    {
        $tournament = $this->tournament();
        $pending = $this->round($tournament, 1, RoundStatus::Pending);
        $inProgress = $this->round($tournament, 2, RoundStatus::InProgress);
        $finished = $this->round($tournament, 3, RoundStatus::Finished);

        $this->roundsManager($tournament)
            ->assertTableActionHidden('completeRound', $pending)
            ->assertTableActionVisible('completeRound', $inProgress)
            ->assertTableActionHidden('completeRound', $finished);
    }

    public function test_complete_round_refuses_while_matches_have_no_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $this->roundsManager($tournament)->callTableAction('startRound', $round);
        $first = MatchModel::query()->where('round_id', $round->id)->orderBy('table_number')->firstOrFail();
        $first->update(['result' => MatchResult::Draw->value]);

        $this->roundsManager($tournament)
            ->callTableAction('completeRound', $round)
            ->assertNotified(
                Notification::make()
                    ->title(__('tournaments::messages.errors.matches_not_completed'))
                    ->body(__('tournaments::messages.errors.matches_not_completed_detail', ['count' => 1]))
                    ->danger()
            );

        $round->refresh();
        $this->assertSame(RoundStatus::InProgress, $round->status);
        $this->assertNull($round->completed_at);
    }

    public function test_complete_round_finishes_a_round_with_every_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->roundsManager($tournament)->callTableAction('generateRound');
        $round = RoundModel::query()->where('tournament_id', $tournament->id)->sole();
        $this->roundsManager($tournament)->callTableAction('startRound', $round);
        MatchModel::query()->where('round_id', $round->id)->update(['result' => MatchResult::PlayerOneWin->value]);

        $this->roundsManager($tournament)
            ->callTableAction('completeRound', $round)
            ->assertNotified(__('tournaments::messages.messages.round_completed'));

        $round->refresh();
        $this->assertSame(RoundStatus::Finished, $round->status);
        $this->assertNotNull($round->completed_at);
        $this->assertLessThan(5, abs(now()->diffInSeconds($round->completed_at)));
    }

    public function test_a_second_complete_round_click_changes_nothing(): void
    {
        $tournament = $this->tournament();
        $round = $this->round($tournament, 1, RoundStatus::InProgress);
        // Both clicks open the confirmation while the round is still in progress
        $first = $this->roundsManager($tournament)->mountTableAction('completeRound', $round);
        $second = $this->roundsManager($tournament)->mountTableAction('completeRound', $round);
        $first->callMountedTableAction();
        $completedAt = $round->refresh()->completed_at?->toIso8601String();
        $this->travel(1)->minutes();
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);

        $second->callMountedTableAction()->assertNotNotified();

        $round->refresh();
        $this->assertSame(RoundStatus::Finished, $round->status);
        $this->assertSame($completedAt, $round->completed_at?->toIso8601String());
        $this->roundsManager($tournament)->assertTableActionHidden('completeRound', $round);
    }

    public function test_start_and_complete_round_go_through_the_round_service(): void
    {
        $tournament = $this->tournament();
        $round = $this->round($tournament, 1, RoundStatus::Pending);
        // The real round service, recording the start and complete calls
        $calls = [];
        $record = static function (string $call) use (&$calls): void {
            $calls[] = $call;
        };
        $service = new class($this->app->make(RoundManagementServiceInterface::class), $record) implements RoundManagementServiceInterface
        {
            public function __construct(private readonly RoundManagementServiceInterface $inner, private readonly \Closure $record) {}

            public function generateNextRound(string $tournamentId): RoundResponseDTO
            {
                return $this->inner->generateNextRound($tournamentId);
            }

            public function startRound(string $roundId): RoundResponseDTO
            {
                ($this->record)('startRound:'.$roundId);

                return $this->inner->startRound($roundId);
            }

            public function completeRound(string $roundId): RoundResponseDTO
            {
                ($this->record)('completeRound:'.$roundId);

                return $this->inner->completeRound($roundId);
            }

            public function find(string $roundId): ?RoundResponseDTO
            {
                return $this->inner->find($roundId);
            }

            public function findByTournament(string $tournamentId): array
            {
                return $this->inner->findByTournament($tournamentId);
            }

            public function getCurrentRound(string $tournamentId): ?RoundResponseDTO
            {
                return $this->inner->getCurrentRound($tournamentId);
            }
        };
        $this->app->instance(RoundManagementServiceInterface::class, $service);

        $this->roundsManager($tournament)->callTableAction('startRound', $round);
        $this->roundsManager($tournament)->callTableAction('completeRound', $round);

        $this->assertSame(['startRound:'.$round->id, 'completeRound:'.$round->id], $calls);
        $this->assertSame(RoundStatus::Finished, $round->refresh()->status);
    }

    public function test_a_round_changed_meanwhile_shows_a_notice_and_saves_nothing(): void
    {
        $tournament = $this->tournament();
        $pending = $this->round($tournament, 1, RoundStatus::Pending);
        $inProgress = $this->round($tournament, 2, RoundStatus::InProgress);
        // Another admin moved both rounds on between the page load and the confirmation
        $this->app->instance(RoundManagementServiceInterface::class, $this->roundServiceRefusingTransitions());

        $this->roundsManager($tournament)
            ->callTableAction('startRound', $pending)
            ->assertNotified(__('tournaments::messages.errors.invalid_state_transition'));
        $this->roundsManager($tournament)
            ->callTableAction('completeRound', $inProgress)
            ->assertNotified(__('tournaments::messages.errors.invalid_state_transition'));

        $this->assertSame(RoundStatus::Pending, $pending->refresh()->status);
        $this->assertSame(RoundStatus::InProgress, $inProgress->refresh()->status);
    }

    private function roundServiceRefusingTransitions(): RoundManagementServiceInterface
    {
        return new class($this->app->make(RoundManagementServiceInterface::class)) implements RoundManagementServiceInterface
        {
            public function __construct(private readonly RoundManagementServiceInterface $inner) {}

            public function generateNextRound(string $tournamentId): RoundResponseDTO
            {
                return $this->inner->generateNextRound($tournamentId);
            }

            public function startRound(string $roundId): RoundResponseDTO
            {
                throw InvalidStateTransitionException::round($roundId, 'in_progress', 'in_progress');
            }

            public function completeRound(string $roundId): RoundResponseDTO
            {
                throw InvalidStateTransitionException::round($roundId, 'finished', 'finished');
            }

            public function find(string $roundId): ?RoundResponseDTO
            {
                return $this->inner->find($roundId);
            }

            public function findByTournament(string $tournamentId): array
            {
                return $this->inner->findByTournament($tournamentId);
            }

            public function getCurrentRound(string $tournamentId): ?RoundResponseDTO
            {
                return $this->inner->getCurrentRound($tournamentId);
            }
        };
    }

    private function round(TournamentModel $tournament, int $number, RoundStatus $status): RoundModel
    {
        return RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'round_number' => $number,
            'status' => $status->value,
            'started_at' => $status === RoundStatus::Pending ? null : now()->subHour(),
            'completed_at' => $status === RoundStatus::Finished ? now()->subMinutes(10) : null,
        ])->refresh();
    }

    /**
     * @return Testable<RoundsRelationManager>
     */
    private function roundsManager(TournamentModel $tournament): Testable
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
