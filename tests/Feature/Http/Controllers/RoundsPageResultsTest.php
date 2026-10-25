<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\MatchResult;
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

    private function participant(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
    }
}
