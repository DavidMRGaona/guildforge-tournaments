<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The two players report, confirm and dispute their match from the tournament page.
 */
final class MatchReportEndpointsTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $round;

    private MatchModel $match;

    private UserModel $one;

    private UserModel $two;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create(['start_date' => now()->subHours(2)])->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::lower(Str::random(6)),
            'status' => 'in_progress',
            'started_at' => now()->subHour(),
            'result_reporting' => 'players_with_confirmation',
            'notification_email' => 'organizacion@example.com',
            'stat_definitions' => [
                ['key' => 'vp', 'name' => 'Puntos de victoria', 'type' => 'integer', 'min_value' => 0, 'max_value' => 100, 'per_player' => true, 'required' => true],
                ['key' => 'painted', 'name' => 'Ejército pintado', 'type' => 'boolean', 'per_player' => true, 'required' => false],
            ],
        ]);
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(30),
        ]);
        $this->one = UserModel::factory()->create(['name' => 'Ana']);
        $this->two = UserModel::factory()->create(['name' => 'Luis']);
        $this->match = MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant($this->one)->id,
            'player_2_id' => $this->participant($this->two)->id,
            'table_number' => 1,
            'result' => 'not_played',
        ]);
    }

    public function test_a_player_reports_and_the_rival_confirms(): void
    {
        $this->actingAs($this->one)
            ->postJson($this->url('resultado'), $this->validReport())
            ->assertOk()
            ->assertJsonPath('outcome', 'awaiting_confirmation');

        $this->actingAs($this->two)
            ->postJson($this->url('confirmar'), ['report' => $this->fingerprint()])
            ->assertOk();

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame(['vp' => 20, 'painted' => true], $match->player_1_stats);
        $this->assertSame($this->two->id, $match->confirmed_by_id);
    }

    public function test_the_rival_disputes_with_a_reason(): void
    {
        $this->actingAs($this->one)->postJson($this->url('resultado'), $this->validReport())->assertOk();

        $this->actingAs($this->two)
            ->postJson($this->url('discutir'), ['report' => $this->fingerprint(), 'reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->two)
            ->postJson($this->url('discutir'), ['report' => $this->fingerprint(), 'reason' => str_repeat('a', 501)])
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->two)
            ->postJson($this->url('discutir'), ['report' => $this->fingerprint(), 'reason' => 'We drew'])
            ->assertOk();

        $this->assertTrue($this->reports()->find($this->match->id)?->isDisputed());
    }

    public function test_guest_is_refused(): void
    {
        $this->postJson($this->url('resultado'), $this->validReport())->assertUnauthorized();

        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_non_player_gets_403(): void
    {
        $this->actingAs(UserModel::factory()->create())
            ->postJson($this->url('resultado'), $this->validReport())
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_unknown_match_gets_404(): void
    {
        $this->actingAs($this->one)
            ->postJson('/torneos/partidas/'.Str::uuid().'/resultado', $this->validReport())
            ->assertNotFound();
    }

    public function test_unknown_stat_key_or_out_of_range_gets_422_and_nothing_is_stored(): void
    {
        $cases = [
            'unknown key' => [['vp' => 20, 'hacked' => 1], ['vp' => 15]],
            'over the maximum' => [['vp' => 101], ['vp' => 15]],
            'missing required stat' => [[], ['vp' => 15]],
            'not a number' => [['vp' => 'veinte'], ['vp' => 15]],
        ];

        foreach ($cases as $case => [$stats1, $stats2]) {
            $this->actingAs($this->one)
                ->postJson($this->url('resultado'), [...$this->validReport(), 'player_1_stats' => $stats1, 'player_2_stats' => $stats2])
                ->assertUnprocessable();
            $this->assertNull($this->reports()->find($this->match->id), $case);
        }

        $this->actingAs($this->one)
            ->postJson($this->url('resultado'), [...$this->validReport(), 'result' => 'double_loss'])
            ->assertJsonValidationErrors('result');
    }

    public function test_scores_that_contradict_the_result_get_422(): void
    {
        $this->actingAs($this->one)
            ->postJson($this->url('resultado'), [...$this->validReport(), 'player_1_score' => 10, 'player_2_score' => 20])
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);
    }

    public function test_report_after_round_finished_gets_409(): void
    {
        $this->round->update(['status' => 'finished']);

        $this->actingAs($this->one)
            ->postJson($this->url('resultado'), $this->validReport())
            ->assertConflict();
    }

    public function test_confirming_a_changed_report_gets_409(): void
    {
        $this->actingAs($this->one)->postJson($this->url('resultado'), $this->validReport())->assertOk();
        $seen = $this->fingerprint();
        $this->actingAs($this->one)->postJson($this->url('resultado'), [...$this->validReport(), 'player_1_score' => 30])->assertOk();

        $this->actingAs($this->two)
            ->postJson($this->url('confirmar'), ['report' => $seen])
            ->assertConflict();

        $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
    }

    public function test_unverified_email_is_blocked_when_required(): void
    {
        app(SettingsServiceInterface::class)->set('email_verification_enforced_since', '2026-01-01T00:00:00+00:00');
        app(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');
        $this->one->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->one)
            ->postJson($this->url('resultado'), $this->validReport())
            ->assertForbidden();

        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_rate_limited_after_ten(): void
    {
        $stranger = UserModel::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($stranger)->postJson($this->url('resultado'), $this->validReport())->assertForbidden();
        }

        $this->actingAs($stranger)
            ->postJson($this->url('resultado'), $this->validReport())
            ->assertTooManyRequests();
    }

    public function test_show_page_sends_my_match_only_to_the_player(): void
    {
        $this->actingAs($this->one)->postJson($this->url('resultado'), $this->validReport())->assertOk();

        $this->actingAs($this->two)
            ->get(route('tournaments.show', $this->tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('myMatch.match.id', $this->match->id)
                ->where('myMatch.side', 2)
                ->where('myMatch.opponent_name', 'Ana')
                ->where('myMatch.actions.can_confirm', true)
                ->where('myMatch.report.fingerprint', $this->fingerprint())
                ->where('myMatch.stat_definitions.0.key', 'vp'));

        $this->actingAs(UserModel::factory()->create())
            ->get(route('tournaments.show', $this->tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('myMatch', null));

        auth()->logout();

        $this->get(route('tournaments.show', $this->tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('myMatch', null))
            ->assertDontSee($this->fingerprint());
    }

    /**
     * @return array<string, mixed>
     */
    private function validReport(): array
    {
        return [
            'result' => 'player_one_win',
            'player_1_score' => 20,
            'player_2_score' => 15,
            'player_1_stats' => ['vp' => 20, 'painted' => true],
            'player_2_stats' => ['vp' => 15],
        ];
    }

    private function fingerprint(): string
    {
        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);

        return $report->fingerprint();
    }

    private function url(string $action): string
    {
        return "/torneos/partidas/{$this->match->id}/{$action}";
    }

    private function participant(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return app(MatchReportRepositoryInterface::class);
    }
}
