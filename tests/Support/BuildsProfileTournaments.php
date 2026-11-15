<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Tournaments and registrations for the tests of the public profile's section and counter.
 */
trait BuildsProfileTournaments
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status = TournamentStatus::InProgress, array $attributes = [], ?EventModel $event = null): TournamentModel
    {
        $event ??= EventModel::factory()->published()->create(['start_date' => now()->addDays(3), 'end_date' => now()->addDays(3)->addHours(6)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo '.Str::random(6),
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => $status,
            'allow_guests' => false,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            'show_participants' => true,
            ...$attributes,
        ]);
    }

    private function enroll(TournamentModel $tournament, UserModel $user, ParticipantStatus $status = ParticipantStatus::Confirmed, ?int $rank = null, float $points = 0.0): ParticipantModel
    {
        $participant = ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'registered_at' => now()->subDay(),
        ]);

        if ($rank !== null) {
            StandingModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'participant_id' => $participant->id,
                'rank' => $rank,
                'points' => $points,
            ]);
        }

        return $participant;
    }

    /**
     * A match of the first round, with its official result (a bye when there is no opponent).
     */
    private function playMatch(TournamentModel $tournament, ParticipantModel $first, ?ParticipantModel $second): void
    {
        $round = RoundModel::query()->firstOrCreate(
            ['tournament_id' => $tournament->id, 'round_number' => 1],
            ['id' => (string) Str::uuid(), 'status' => RoundStatus::InProgress->value],
        );

        MatchModel::create([
            'id' => (string) Str::uuid(),
            'round_id' => $round->id,
            'player_1_id' => $first->id,
            'player_2_id' => $second?->id,
            'result' => ($second instanceof ParticipantModel ? MatchResult::PlayerOneWin : MatchResult::Bye)->value,
        ]);
    }

    /**
     * The standings as the module computes them: the rows the tournament's page reads.
     */
    private function recalculateStandings(TournamentModel $tournament): void
    {
        app(StandingsServiceInterface::class)->recalculate($tournament->id);
    }

    private function organize(TournamentModel $tournament, UserModel $user): void
    {
        DB::table('tournaments_organizers')->insert(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    }

    private function ownerDto(UserModel $user): ProfileOwnerDTO
    {
        return new ProfileOwnerDTO((string) $user->id, 'ana-garcia', 'Ana García');
    }
}
