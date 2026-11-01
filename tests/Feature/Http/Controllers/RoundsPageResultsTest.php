<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class RoundsPageResultsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_rounds_page_sends_the_enum_values_the_frontend_maps(): void
    {
        app()->setLocale('es');

        $tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Rondas',
            'slug' => 'rondas-resultados',
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
        ]);
        $round = RoundModel::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $one = $this->participant($tournament);
        $two = $this->participant($tournament);

        foreach (MatchResult::cases() as $result) {
            MatchModel::create([
                'round_id' => $round->id,
                'player_1_id' => $one->id,
                'player_2_id' => $result === MatchResult::Bye ? null : $two->id,
                'result' => $result->value,
            ]);
        }

        $this->get('/torneos/rondas-resultados/rondas')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $matches = $page->toArray()['props']['rounds'][0]['matches'];

                $this->assertEqualsCanonicalizing(
                    ['player_one_win', 'player_two_win', 'draw', 'double_loss', 'bye', 'not_played'],
                    array_column($matches, 'result'),
                );

                $byResult = array_column($matches, 'result_label', 'result');
                $this->assertSame('Victoria jugador 1', $byResult['player_one_win']);
                $this->assertSame('Sin jugar', $byResult['not_played']);
            });
    }

    public function test_a_result_recorded_in_the_panel_never_shows_pending_confirmation(): void
    {
        [$tournament, $round, $one, $two] = $this->roundInProgress('rondas-panel');
        $match = MatchModel::create([
            'round_id' => $round->id,
            'player_1_id' => $one->id,
            'player_2_id' => $two->id,
            'result' => 'not_played',
        ]);
        app(MatchResultServiceInterface::class)->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, UserModel::factory()->create()->id));

        $this->get('/torneos/rondas-panel/rondas')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $match = $page->toArray()['props']['rounds'][0]['matches'][0];

                $this->assertFalse($match['needs_confirmation']);
                $this->assertFalse($match['is_disputed']);
                $this->assertNull($match['report']);
            });
    }

    public function test_pending_and_disputed_reports_show_on_the_rounds_page_without_reasons(): void
    {
        [$tournament, $round, $one, $two] = $this->roundInProgress('rondas-informes');
        $three = $this->participant($tournament);
        $four = $this->participant($tournament);
        $pending = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $one->id, 'player_2_id' => $two->id, 'result' => 'not_played', 'table_number' => 1]);
        $disputed = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $three->id, 'player_2_id' => $four->id, 'result' => 'not_played', 'table_number' => 2]);
        $reports = app(MatchReportRepositoryInterface::class);
        $reports->save(new MatchReport($pending->id, MatchResult::PlayerOneWin, (string) $one->user_id, new DateTimeImmutable('2030-05-01 10:00:00')));
        $report = new MatchReport($disputed->id, MatchResult::Draw, (string) $three->user_id, new DateTimeImmutable('2030-05-01 10:00:00'));
        $report->dispute((string) $four->user_id, 'A private reason', new DateTimeImmutable('2030-05-01 10:05:00'));
        $reports->save($report);

        $this->get('/torneos/rondas-informes/rondas')
            ->assertOk()
            ->assertDontSee('A private reason')
            ->assertInertia(function (Assert $page) use ($pending, $disputed): void {
                $matches = array_column($page->toArray()['props']['rounds'][0]['matches'], null, 'id');

                $this->assertSame(['status' => 'pending'], $matches[$pending->id]['report']);
                $this->assertTrue($matches[$pending->id]['needs_confirmation']);
                $this->assertSame(['status' => 'disputed'], $matches[$disputed->id]['report']);
                $this->assertTrue($matches[$disputed->id]['is_disputed']);
                $this->assertFalse($matches[$disputed->id]['needs_confirmation']);
            });
    }

    public function test_the_rounds_page_reads_the_reports_without_a_query_per_report(): void
    {
        [$tournament, $round, $one, $two] = $this->roundInProgress('rondas-consultas');
        $three = $this->participant($tournament);
        $four = $this->participant($tournament);
        $first = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $one->id, 'player_2_id' => $two->id, 'result' => 'not_played']);
        $second = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $three->id, 'player_2_id' => $four->id, 'result' => 'not_played']);
        $queries = function () use ($round): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(MatchQueryServiceInterface::class)->getMatchesForRound($round->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $withoutReports = $queries();

        $reports = app(MatchReportRepositoryInterface::class);
        $reports->save(new MatchReport($first->id, MatchResult::PlayerOneWin, (string) $one->user_id, new DateTimeImmutable('2030-05-01 10:00:00')));
        $reports->save(new MatchReport($second->id, MatchResult::Draw, (string) $three->user_id, new DateTimeImmutable('2030-05-01 10:00:00')));

        $this->assertSame($withoutReports, $queries());
    }

    public function test_a_report_left_next_to_an_official_result_is_not_shown_as_pending(): void
    {
        [$tournament, $round, $one, $two] = $this->roundInProgress('rondas-huerfano');
        $match = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $one->id, 'player_2_id' => $two->id, 'result' => 'draw']);
        app(MatchReportRepositoryInterface::class)->save(new MatchReport($match->id, MatchResult::PlayerOneWin, (string) $one->user_id, new DateTimeImmutable('2030-05-01 10:00:00')));

        $this->get('/torneos/rondas-huerfano/rondas')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $match = $page->toArray()['props']['rounds'][0]['matches'][0];

                $this->assertNull($match['report']);
                $this->assertFalse($match['needs_confirmation']);
            });
    }

    /**
     * @return array{TournamentModel, RoundModel, ParticipantModel, ParticipantModel}
     */
    private function roundInProgress(string $slug): array
    {
        $tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Rondas',
            'slug' => $slug,
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
            'result_reporting' => 'players_with_confirmation',
            'notification_email' => 'test@example.com',
        ]);
        $round = RoundModel::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        return [$tournament, $round, $this->participant($tournament), $this->participant($tournament)];
    }

    private function participant(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
    }
}
