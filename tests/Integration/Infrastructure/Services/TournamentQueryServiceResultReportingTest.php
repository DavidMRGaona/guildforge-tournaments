<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Whoever holds tournaments.report_results can report any match, whatever role grants it;
 * otherwise only the match's players, and only when the tournament lets players report.
 */
final class TournamentQueryServiceResultReportingTest extends ModuleTestCase
{
    private const string REPORT_RESULTS = 'tournaments:tournaments.report_results';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentQueryServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TournamentQueryServiceInterface::class);
    }

    public function test_a_role_with_report_results_can_report_in_an_organisers_only_tournament(): void
    {
        $match = $this->match(ResultReporting::AdminOnly);

        $this->assertTrue($this->service->canUserReportResult($match->id, $this->userWithReportResults()->id));
    }

    public function test_an_admin_can_report_in_an_organisers_only_tournament(): void
    {
        $match = $this->match(ResultReporting::AdminOnly);

        $this->assertTrue($this->service->canUserReportResult($match->id, UserModel::factory()->admin()->create()->id));
    }

    public function test_an_editor_without_report_results_cannot_report_in_an_organisers_only_tournament(): void
    {
        $match = $this->match(ResultReporting::AdminOnly);

        $this->assertFalse($this->service->canUserReportResult($match->id, UserModel::factory()->editor()->create()->id));
    }

    public function test_a_player_of_the_match_can_report_when_players_report(): void
    {
        $player = UserModel::factory()->create();
        $match = $this->match(ResultReporting::PlayersTrusted, $player);

        $this->assertTrue($this->service->canUserReportResult($match->id, $player->id));
        $this->assertFalse($this->service->canUserReportResult($match->id, UserModel::factory()->create()->id));
    }

    public function test_an_unknown_user_cannot_report(): void
    {
        $match = $this->match(ResultReporting::AdminOnly);

        $this->assertFalse($this->service->canUserReportResult($match->id, (string) Str::uuid()));
    }

    private function userWithReportResults(): UserModel
    {
        $role = RoleModel::create(['name' => 'results-'.Str::random(6), 'display_name' => 'Resultados']);
        $permission = PermissionModel::firstOrCreate(
            ['key' => self::REPORT_RESULTS],
            ['label' => 'Reportar resultados', 'resource' => 'tournaments', 'action' => 'report_results', 'module' => 'tournaments'],
        );
        $role->permissions()->attach($permission->id);

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function match(ResultReporting $reporting, ?UserModel $player = null): MatchModel
    {
        $tournament = TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de resultados',
            'slug' => 'torneo-de-resultados-'.Str::random(6),
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => $reporting->value,
            'requires_manual_confirmation' => true,
        ]);

        $round = RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => RoundStatus::InProgress->value,
            'started_at' => now(),
        ]);

        return MatchModel::create([
            'id' => (string) Str::uuid(),
            'round_id' => $round->id,
            'player_1_id' => $this->participant($tournament, $player)->id,
            'player_2_id' => $this->participant($tournament)->id,
            'result' => MatchResult::NotPlayed->value,
            'table_number' => 1,
        ]);
    }

    private function participant(TournamentModel $tournament, ?UserModel $user = null): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user?->id,
            'guest_name' => $user instanceof UserModel ? null : 'Jugador '.Str::random(4),
            'guest_email' => $user instanceof UserModel ? null : Str::random(8).'@example.com',
            'status' => ParticipantStatus::Confirmed->value,
            'registered_at' => now(),
        ]);
    }
}
